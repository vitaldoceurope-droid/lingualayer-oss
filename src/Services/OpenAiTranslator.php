<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible backend. Talks to any /chat/completions endpoint that
 * follows the OpenAI schema — OpenAI itself, but also Groq, Together, Mistral,
 * Ollama, vLLM, LocalAI or a future self-hosted model — by pointing
 * lingua.openai.base_url at it. All translation orchestration is inherited from
 * AbstractLlmTranslator; only the HTTP call differs from Gemini.
 */
class OpenAiTranslator extends AbstractLlmTranslator
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    public function __construct(TranslationCache $cache)
    {
        parent::__construct($cache);
        $this->apiKey = (string) config('lingua.openai.api_key', '');
        $this->model = (string) config('lingua.openai.model', 'gpt-4o-mini');
        $this->baseUrl = rtrim((string) config('lingua.openai.base_url', 'https://api.openai.com/v1'), '/');
    }

    protected function callModel(string $text, string $targetLang, string $systemPrompt): string
    {
        TranslationCache::bumpStat(TranslationCache::STATS_CALLS_TOTAL);

        $http = Http::timeout(60)->acceptJson()->asJson();

        if ($this->apiKey !== '') {
            $http = $http->withToken($this->apiKey);
        }

        // Skip SSL verification in local/dev (self-signed local model servers,
        // missing CA bundle on Windows/XAMPP).
        if (! app()->isProduction()) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post($this->baseUrl.'/chat/completions', [
            'model' => $this->model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt !== '' ? $systemPrompt : self::BASE_PROMPT],
                ['role' => 'user',   'content' => "Idioma destino: {$targetLang}\n\nTexto a traducir:\n{$text}"],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'OpenAI-compatible API error '.$response->status().': '.$response->body()
            );
        }

        return (string) $response->json('choices.0.message.content', $text);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        // A non-default base_url (a local/self-hosted model) is enough; a hosted
        // OpenAI endpoint additionally needs an API key.
        if ($this->baseUrl !== 'https://api.openai.com/v1') {
            return true;
        }

        return $this->apiKey !== '';
    }
}

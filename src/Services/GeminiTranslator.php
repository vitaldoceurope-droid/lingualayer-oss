<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Http;

/**
 * Google Gemini backend. All orchestration (caching, batching, delimiter
 * protocol, retries, few-shot, prompt building) lives in AbstractLlmTranslator;
 * this class only knows how to talk to the Gemini generateContent endpoint.
 */
class GeminiTranslator extends AbstractLlmTranslator
{
    private string $apiKey;

    private string $model;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(TranslationCache $cache)
    {
        parent::__construct($cache);
        $this->apiKey = (string) config('lingua.gemini_api_key', '');
        $this->model = (string) config('lingua.gemini_model', 'gemini-2.5-flash');
    }

    protected function callModel(string $text, string $targetLang, string $systemPrompt): string
    {
        TranslationCache::bumpStat(TranslationCache::STATS_CALLS_TOTAL);

        $url = self::API_BASE."/{$this->model}:generateContent?key={$this->apiKey}";

        $http = Http::timeout(60);

        // Skip SSL verification in local/dev — cURL on Windows/XAMPP often lacks
        // the CA bundle needed to verify Google's certificate.
        if (! app()->isProduction()) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post($url, [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt !== '' ? $systemPrompt : self::BASE_PROMPT]],
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => "Idioma destino: {$targetLang}\n\nTexto a traducir:\n{$text}"],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Gemini API error '.$response->status().': '.$response->body()
            );
        }

        return (string) $response->json('candidates.0.content.parts.0.text', $text);
    }

    public function getName(): string
    {
        return 'gemini-direct';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }
}

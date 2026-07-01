<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Contracts\TranslatorInterface;

/**
 * Provider-neutral translation engine. Owns everything that is identical across
 * LLM backends — caching, chunking, the ⟦LL:N⟧ delimiter protocol, alignment
 * validation, retries, few-shot prompting and the domain/formality/glossary
 * prompt builder — and leaves only the actual model call to subclasses.
 *
 * To add a provider (OpenAI, Anthropic, DeepL, a self-hosted OpenAI-compatible
 * model…), extend this class and implement callModel()/getName()/isConfigured().
 * That is the entire surface a new backend has to fill in.
 */
abstract class AbstractLlmTranslator implements TranslatorInterface
{
    protected string $sourceLang;

    // Brackets each item so we can split the response AND validate the count,
    // catching silent alignment drift in multi-item batches.
    protected const ITEM_OPEN = '⟦LL:';

    protected const ITEM_CLOSE = '⟧';

    protected const BASE_PROMPT = <<<'PROMPT'
Tu única función es traducir cada fragmento de texto que recibes al idioma indicado.

Formato ESTRICTO de entrada y salida:
- Cada fragmento viene delimitado así: ⟦LL:N⟧texto⟦/LL:N⟧ (donde N es un índice).
- Debes responder con EXACTAMENTE los mismos delimitadores y los mismos índices: ⟦LL:N⟧traducción⟦/LL:N⟧.
- El número de fragmentos de salida DEBE coincidir con el de entrada.
- No uses comentarios, explicaciones, comillas, asteriscos, listas, ni markdown alrededor.

Marcadores técnicos (CRÍTICO):
- Cualquier token entre ⟦ y ⟧ —por ejemplo ⟦#0⟧, ⟦#1⟧— sustituye a una variable, código, URL o término protegido.
- Cópialos EXACTAMENTE, en la misma posición relativa. Nunca los traduzcas, re-numeres, elimines, ni les añadas espacios dentro.
- La traducción debe contener exactamente los mismos tokens ⟦#N⟧ que el original.

Reglas de contenido:
- Preserva nombres propios, emails, URLs, teléfonos, códigos y números exactamente como están.
- Si un fragmento ya está en el idioma destino, devuélvelo sin cambios pero con sus delimitadores.
- Mantén la capitalización y la puntuación propias del idioma destino.
- Traduce con naturalidad y fidelidad; no añadas ni quites información.
PROMPT;

    /**
     * Per-domain register/terminology guidance appended to the role line.
     * 'generic' is the safe neutral default; any value not listed here is
     * treated as a free-text domain (e.g. 'veterinaria').
     */
    private const DOMAIN_GUIDANCE = [
        'generic' => '.',
        'medical' => ' especializado en documentación médica y clínica. Usa terminología médica precisa; nunca alteres nombres de fármacos, dosis ni unidades.',
        'legal' => ' especializado en textos jurídicos. Usa terminología legal precisa y registro formal.',
        'ecommerce' => ' especializado en e-commerce. Mantén un tono comercial fiel; nunca cambies SKUs, tallas, referencias ni precios.',
        'technical' => ' especializado en documentación técnica y software. Conserva términos técnicos, nombres de API, comandos y código sin traducir.',
        'finance' => ' especializado en finanzas. Usa terminología financiera precisa y conserva cifras, divisas y símbolos.',
        'hospitality' => ' especializado en turismo y hostelería. Usa un tono cálido y acogedor manteniendo la fidelidad.',
    ];

    public function __construct(protected TranslationCache $cache)
    {
        $this->sourceLang = (string) config('lingua.source_language', 'en');
    }

    /**
     * The single provider-specific operation: send one already-delimited payload
     * to the model and return its raw text response. Must throw on transport or
     * API errors so the retry loop can react.
     */
    abstract protected function callModel(string $text, string $targetLang, string $systemPrompt): string;

    /** Items per request. Override per provider if its context window allows more. */
    protected function chunkSize(): int
    {
        // Bigger chunks = far fewer sequential Gemini round-trips per page
        // (a landing of ~200 strings drops from ~10 calls to ~3). Tunable via
        // LINGUA_CHUNK_SIZE; gemini-2.5-flash handles this easily and the real
        // ceiling is output tokens, not input count.
        return max(1, (int) config('lingua.translation.chunk_size', 60));
    }

    public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
    {
        $text = trim($text);
        if ($this->shouldSkip($text)) {
            return $text;
        }

        // Route single strings through translateBatch so they share the exact
        // same delimited prompt + parser as batches.
        $result = $this->translateBatch([$text], $target, $source, $context);

        if ($result === null || ! isset($result[0])) {
            return $text;
        }

        return $result[0];
    }

    /**
     * @param  string[]  $texts
     * @return array<int,string>|null keyed by input position, or null on atomic failure
     */
    public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
    {
        $targetLang = $target;
        $results = [];
        $toFetch = [];
        $fetchKeys = [];

        foreach ($texts as $i => $text) {
            $text = trim($text);
            if ($this->shouldSkip($text)) {
                $results[$i] = $text;

                continue;
            }
            $cached = $this->cache->get($text, $targetLang);
            if ($cached !== null) {
                $results[$i] = $cached;
                TranslationCache::bumpStat(TranslationCache::STATS_HITS_TOTAL);
            } else {
                $toFetch[$i] = $text;
                $fetchKeys[] = $i;
            }
        }

        if (empty($toFetch)) {
            ksort($results);

            return $results;
        }

        $examples = $this->getFewShotExamples($targetLang, $context);
        $systemPrompt = $this->buildSystemPrompt($examples, $targetLang);

        if (! empty($examples)) {
            Log::channel('single')->info('[LinguaLayer] Few-shot prompt active', [
                'lang' => $targetLang,
                'examples' => count($examples),
            ]);
        }

        $chunkSize = $this->chunkSize();
        $fetchValues = array_values($toFetch);
        $fetchIdxs = $fetchKeys; // already a 0-based list
        $chunks = array_chunk($fetchValues, $chunkSize);
        $idxChunks = array_chunk($fetchIdxs, $chunkSize);

        foreach ($chunks as $c => $chunk) {
            $chunkResult = $this->translateChunkWithRetry($chunk, $targetLang, $systemPrompt);

            if ($chunkResult === null) {
                // A whole chunk failing usually means the model drifted on the
                // ⟦LL:N⟧ delimiters (likelier the more items it has) or hit a
                // transient rate-limit — NOT that the content is untranslatable.
                // Recover by splitting the chunk into halves before giving up,
                // instead of throwing every fragment in the batch away (which
                // left entire SPA pages untranslated when one chunk failed).
                $chunkResult = $this->translateChunkSplitting($chunk, $targetLang, $systemPrompt);
            }

            // ATOMIC guarantee: a page is fully translated or fully in the
            // source language — never half. If even recovery left an item
            // untranslated, fail the whole batch so the caller serves source.
            if (count($chunkResult) !== count($chunk) || in_array(null, $chunkResult, true)) {
                return null;
            }

            foreach ($chunk as $n => $original) {
                $translatedText = $chunkResult[$n] ?? $original;
                $idx = $idxChunks[$c][$n];
                $results[$idx] = $translatedText;
                $this->cache->set($original, $targetLang, $translatedText);
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Fetch high-quality examples from lingua_training_samples for few-shot
     * prompting. Returns [] when disabled, empty, or on any DB error.
     *
     * @return array<int,array{source:string,target:string}>
     */
    public function getFewShotExamples(string $targetLang, ?string $context = null): array
    {
        if (! config('lingua.few_shot_enabled', false)) {
            return [];
        }

        $cacheDriver = config('lingua.cache_driver', 'file');
        $cacheKey = 'lingua_fewshot_'.md5($this->sourceLang.$targetLang.($context ?? ''));
        $cached = Cache::driver($cacheDriver)->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $maxExamples = (int) config('lingua.few_shot_max_examples', 5);

            $rows = DB::table('lingua_training_samples')
                ->where('source_lang', $this->sourceLang)
                ->where('target_lang', $targetLang)
                ->where('score', '>=', 8)
                ->when($context, fn ($q) => $q->where('context', $context))
                ->orderByDesc('score')
                ->limit($maxExamples)
                ->get(['source_text', 'translated_text']);

            $examples = $rows->map(fn ($r) => [
                'source' => $r->source_text,
                'target' => $r->translated_text,
            ])->all();

            if (! empty($examples)) {
                $ttl = (int) config('lingua.few_shot_cache_hours', 24) * 3600;
                Cache::driver($cacheDriver)->put($cacheKey, $examples, $ttl);
            }

            return $examples;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build the system prompt: domain-aware role + strict format/preservation
     * rules + formality + per-language glossary + optional few-shot examples.
     *
     * @param  array<int,array{source:string,target:string}>  $examples
     */
    public function buildSystemPrompt(array $examples, ?string $targetLang = null): string
    {
        $prompt = $this->roleLine()."\n\n".self::BASE_PROMPT;

        $formality = $this->formalityLine();
        if ($formality !== '') {
            $prompt .= "\n\n".$formality;
        }

        if ($targetLang !== null) {
            $glossary = $this->glossaryBlock($targetLang);
            if ($glossary !== '') {
                $prompt .= "\n\n".$glossary;
            }
        }

        if (! empty($examples)) {
            $fewShot = "\n\nEjemplos de traducciones de alta calidad para referencia:";
            foreach ($examples as $ex) {
                $fewShot .= "\nOriginal: {$ex['source']}\nTraducción: {$ex['target']}";
            }
            $fewShot .= "\n\nBasándote en los ejemplos anteriores, mantén el mismo registro y estilo.";
            $prompt .= $fewShot;
        }

        return $prompt;
    }

    /** Role sentence, specialised by config('lingua.translation.domain'). */
    private function roleLine(): string
    {
        $domain = strtolower(trim((string) config('lingua.translation.domain', 'generic')));
        $suffix = self::DOMAIN_GUIDANCE[$domain]
            ?? ($domain !== '' && $domain !== 'generic'
                ? ' especializado en el ámbito de '.$domain.'. Usa la terminología propia de ese sector.'
                : '.');

        return 'Eres un traductor profesional contextual'.$suffix;
    }

    /** Register directive, from config('lingua.translation.formality'). */
    private function formalityLine(): string
    {
        return match (strtolower(trim((string) config('lingua.translation.formality', 'formal')))) {
            'informal' => 'Registro: informal y cercano (tutea: tú/du/tu) en los idiomas que distingan el trato.',
            'neutral' => 'Registro: neutro; evita marcas de segunda persona cuando sea posible.',
            default => 'Registro: formal y profesional (trato de usted/Sie/vous) en los idiomas que distingan el trato.',
        };
    }

    /**
     * Authoritative glossary block for a target language. Accepts a per-language
     * map (['en' => ['Panel' => 'Dashboard']]) or a flat one (['Panel' =>
     * 'Dashboard']) applied to every language. Empty string when nothing applies.
     */
    private function glossaryBlock(string $targetLang): string
    {
        $glossary = (array) config('lingua.translation.glossary', []);
        if (empty($glossary)) {
            return '';
        }

        $entries = [];
        if (isset($glossary[$targetLang]) && is_array($glossary[$targetLang])) {
            $entries = $glossary[$targetLang];
        } else {
            foreach ($glossary as $src => $dst) {
                if (is_string($dst)) {
                    $entries[$src] = $dst;
                }
            }
        }
        if (empty($entries)) {
            return '';
        }

        $block = 'Glosario obligatorio — usa EXACTAMENTE estas traducciones para estos términos:';
        foreach ($entries as $src => $dst) {
            $block .= "\n- «{$src}» → «{$dst}»";
        }

        return $block;
    }

    /**
     * Translate a single chunk with up to 3 retries. Short backoff because we
     * often run on the web-request thread; TranslatePageJob provides the longer
     * retry window. Returns null on total failure.
     *
     * @return array<int,string>|null
     */
    private function translateChunkWithRetry(array $chunk, string $targetLang, string $systemPrompt): ?array
    {
        $maxAttempts = 3;
        $backoffsMicro = [200_000, 500_000];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $joined = '';
                foreach ($chunk as $n => $original) {
                    $joined .= self::ITEM_OPEN.$n.self::ITEM_CLOSE
                        .$original
                        .self::ITEM_OPEN.'/'.$n.self::ITEM_CLOSE."\n";
                }

                $translated = $this->callModel($joined, $targetLang, $systemPrompt);
                $parts = $this->parseDelimited($translated, count($chunk));

                if ($parts === null) {
                    throw new \RuntimeException('Delimiter alignment mismatch');
                }

                $result = [];
                foreach ($chunk as $n => $original) {
                    $t = isset($parts[$n]) ? trim($parts[$n]) : '';
                    $result[$n] = $t !== '' ? $t : $original;
                }

                return $result;
            } catch (\Throwable $e) {
                Log::channel('single')->warning("[LinguaLayer] Chunk attempt {$attempt}/{$maxAttempts} failed", [
                    'error' => $e->getMessage(),
                    'provider' => $this->getName(),
                    'lang' => $targetLang,
                    'count' => count($chunk),
                ]);

                if ($attempt < $maxAttempts) {
                    usleep($backoffsMicro[$attempt - 1]);
                }
            }
        }

        Log::channel('single')->warning('[LinguaLayer] Chunk failed after all retries', [
            'provider' => $this->getName(),
            'lang' => $targetLang,
            'count' => count($chunk),
        ]);

        return null;
    }

    /**
     * Recover a chunk that failed as a whole by translating it in halves. A big
     * chunk most often fails because the model drifted on the ⟦LL:N⟧ delimiters
     * (likelier the more items it carries) or hit a transient rate-limit, not
     * because the content is untranslatable — so splitting usually gets it
     * through. Recurses down to single items. Returns a map keyed by the chunk's
     * original 0-based indices, with null for any item that still could not be
     * translated (the caller then honours the atomic guarantee).
     *
     * @param  array<int,string>  $chunk  0-based original texts
     * @return array<int,string|null>
     */
    private function translateChunkSplitting(array $chunk, string $targetLang, string $systemPrompt): array
    {
        $keys = array_keys($chunk);

        if (count($keys) <= 1) {
            // A single item already exhausted translateChunkWithRetry above.
            return [$keys[0] => null];
        }

        $half = intdiv(count($keys), 2);
        $out = [];

        foreach ([array_slice($keys, 0, $half), array_slice($keys, $half)] as $part) {
            // Re-index the sub-chunk 0-based so the delimiter protocol lines up.
            $sub = [];
            foreach ($part as $k) {
                $sub[] = $chunk[$k];
            }

            $res = $this->translateChunkWithRetry($sub, $targetLang, $systemPrompt);
            if ($res === null) {
                $res = $this->translateChunkSplitting($sub, $targetLang, $systemPrompt);
            }

            foreach ($part as $j => $k) {
                $out[$k] = $res[$j] ?? null;
            }
        }

        return $out;
    }

    /**
     * Parse the model response back into a per-index map using the injected
     * ⟦LL:N⟧…⟦/LL:N⟧ delimiters, with looser fallbacks whose cardinality must
     * still match $expected so a batch can never be silently misaligned.
     *
     * @return array<int,string>|null
     */
    protected function parseDelimited(string $raw, int $expected): ?array
    {
        if (preg_match_all('/⟦LL:(\d+)⟧(.*?)⟦\/LL:\1⟧/us', $raw, $matches, PREG_SET_ORDER)) {
            $out = [];
            foreach ($matches as $m) {
                $out[(int) $m[1]] = $m[2];
            }
            if (count($out) === $expected) {
                return $out;
            }
        }

        $cleaned = trim(preg_replace('/⟦[\/\s]*LL[\/\s]*:[\/\s]*\d+[\/\s]*⟧/u', '', $raw));

        if ($expected === 1 && $cleaned !== '') {
            return [0 => $cleaned];
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $cleaned)),
            fn ($l) => $l !== ''
        ));
        if (count($lines) === $expected) {
            return array_combine(range(0, $expected - 1), $lines);
        }

        return null;
    }

    protected function shouldSkip(string $text): bool
    {
        if (mb_strlen($text) < 3) {
            return true;
        }
        if (is_numeric(str_replace([',', '.', ' '], '', $text))) {
            return true;
        }
        if (preg_match('/^[\W\d]+$/', $text)) {
            return true;
        }
        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        if (filter_var($text, FILTER_VALIDATE_URL)) {
            return true;
        }

        return false;
    }
}

<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationScorer
{
    private const INDEX_KEY = 'lingua_score_index';

    private const MAX_INDEX = 500;

    /**
     * Score a random sample of original→translated pairs.
     * Called from TranslatePageJob (background) — never blocks a web request.
     *
     * @param  array  $originals  [i => string]
     * @param  array  $translated  [i => string]
     */
    public function scoreRandomSample(array $originals, array $translated, string $targetLang): void
    {
        if (! config('lingua.auto_score', false)) {
            return;
        }

        $candidates = array_filter(
            array_keys($originals),
            fn ($i) => mb_strlen($originals[$i] ?? '') >= 20 && ! empty($translated[$i] ?? '')
        );

        if (empty($candidates)) {
            return;
        }

        shuffle($candidates);
        foreach (array_slice($candidates, 0, 3) as $i) {
            $this->scoreOne($originals[$i], $translated[$i], $targetLang);
        }
    }

    private function scoreOne(string $original, string $translated, string $targetLang): void
    {
        $apiKey = config('lingua.gemini_api_key', '');
        $model = config('lingua.gemini_model', 'gemini-2.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = "Evalúa esta traducción del 1 al 10 en precisión y naturalidad.\n"
            ."Texto original: {$original}\n"
            ."Idioma destino: {$targetLang}\n"
            ."Traducción: {$translated}\n\n"
            .'Responde SOLO con JSON: {"score":8,"issues":["problema"]}. Si es perfecta: {"score":10,"issues":[]}';

        try {
            $http = Http::timeout(10);
            if (! app()->isProduction()) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 128],
            ]);

            if (! $response->successful()) {
                return;
            }

            $text = $response->json('candidates.0.content.parts.0.text', '');
            if (! preg_match('/\{[^}]+\}/', $text, $m)) {
                return;
            }

            $data = json_decode($m[0], true);
            if (! is_array($data) || ! isset($data['score'])) {
                return;
            }

            $score = (int) $data['score'];
            $entry = [
                'lang' => $targetLang,
                'score' => $score,
                'issues' => $data['issues'] ?? [],
                'original' => mb_substr($original, 0, 100),
                'translated' => mb_substr($translated, 0, 100),
                'scored_at' => now()->toDateTimeString(),
            ];

            $this->addToIndex($entry);

            // Save high-quality translations for few-shot learning
            if ($score >= 8) {
                $this->saveToTrainingSamples($original, $translated, $targetLang, $score);
            }

            // Invalidate low-quality translations so they get re-translated on next visit
            if ($score < 7) {
                app(TranslationCache::class)->forget($original, $targetLang);
                Log::channel('single')->info('[LinguaLayer] Low-score translation invalidated', $entry);
            }
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] Scoring skipped', ['error' => $e->getMessage()]);
        }
    }

    private function saveToTrainingSamples(
        string $original,
        string $translated,
        string $targetLang,
        int $score
    ): void {
        if (! config('lingua.few_shot_enabled', false)) {
            return;
        }

        try {
            $sourceLang = config('lingua.source_language', 'en');

            $alreadyExists = DB::table('lingua_training_samples')
                ->where('source_lang', $sourceLang)
                ->where('target_lang', $targetLang)
                ->where('source_text', mb_substr($original, 0, 1000))
                ->exists();

            if ($alreadyExists) {
                return;
            }

            DB::table('lingua_training_samples')->insert([
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'source_text' => mb_substr($original, 0, 1000),
                'translated_text' => mb_substr($translated, 0, 1000),
                'score' => $score,
                'context' => null,
                'validated' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::channel('single')->info('[LinguaLayer] Training sample saved', [
                'lang' => $targetLang,
                'score' => $score,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] Could not save training sample', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function addToIndex(array $entry): void
    {
        $driver = config('lingua.cache_driver', 'file');
        $index = Cache::driver($driver)->get(self::INDEX_KEY, []);

        array_unshift($index, $entry);
        if (count($index) > self::MAX_INDEX) {
            $index = array_slice($index, 0, self::MAX_INDEX);
        }

        Cache::driver($driver)->put(self::INDEX_KEY, $index, 86400 * 60 * 30);
    }

    public function getIndex(): array
    {
        return Cache::driver(config('lingua.cache_driver', 'file'))
            ->get(self::INDEX_KEY, []);
    }

    public function getStats(): array
    {
        $index = $this->getIndex();
        if (empty($index)) {
            return ['count' => 0, 'average' => 0, 'low_count' => 0, 'by_lang' => []];
        }

        $scores = array_column($index, 'score');
        $byLang = [];

        foreach ($index as $entry) {
            $byLang[$entry['lang']][] = $entry['score'];
        }

        $langStats = [];
        foreach ($byLang as $lang => $s) {
            $langStats[$lang] = [
                'count' => count($s),
                'average' => round(array_sum($s) / count($s), 1),
            ];
        }

        return [
            'count' => count($index),
            'average' => round(array_sum($scores) / count($scores), 1),
            'low_count' => count(array_filter($scores, fn ($s) => $s < 7)),
            'by_lang' => $langStats,
        ];
    }
}

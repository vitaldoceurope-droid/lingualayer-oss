<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

/**
 * These tests estimate Gemini API cost from request size, not from real
 * billing. The conversion (~4 chars per token, gemini-2.5-flash pricing)
 * is documented at https://ai.google.dev/pricing — values may change.
 */
const GEMINI_INPUT_PRICE_PER_1M_TOKENS = 0.30; // USD, gemini-2.5-flash input
const GEMINI_OUTPUT_PRICE_PER_1M_TOKENS = 2.50; // USD, gemini-2.5-flash output
const CHARS_PER_TOKEN = 4;     // rough heuristic

function estimateCostUsd(int $inputChars, int $outputChars): float
{
    $inputTokens = $inputChars / CHARS_PER_TOKEN;
    $outputTokens = $outputChars / CHARS_PER_TOKEN;

    return ($inputTokens / 1_000_000) * GEMINI_INPUT_PRICE_PER_1M_TOKENS
         + ($outputTokens / 1_000_000) * GEMINI_OUTPUT_PRICE_PER_1M_TOKENS;
}

test('estimates cost of a typical 5KB page below half a cent', function () {
    $body = str_repeat('Texto de prueba para la página de ejemplo. ', 100); // ~4.3 KB
    $cost = estimateCostUsd(strlen($body), strlen($body));

    // 5KB page should always cost < $0.005 (half a cent)
    expect($cost)->toBeLessThan(0.005);
});

test('cache hit avoids the entire Gemini cost for a fragment', function () {
    Http::fake();

    $cache = app(TranslationCache::class);
    $cache->set('Saludo cacheado largo', 'en', 'Cached long greeting');

    $result = app(GeminiTranslator::class)->translateBatch(['Saludo cacheado largo'], 'en');

    expect($result)->toBe([0 => 'Cached long greeting']);
    Http::assertNothingSent();
});

test('100% cache coverage yields zero Gemini calls', function () {
    Http::fake();

    $cache = app(TranslationCache::class);
    $texts = [];
    for ($i = 0; $i < 50; $i++) {
        $texts[] = "Texto numero {$i} con suficiente longitud";
        $cache->set("Texto numero {$i} con suficiente longitud", 'en', "Text number {$i}");
    }

    $result = app(GeminiTranslator::class)->translateBatch($texts, 'en');

    expect(count($result))->toBe(50);
    Http::assertNothingSent();
});

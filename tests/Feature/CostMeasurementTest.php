<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

const PRICE_INPUT_PER_1M = 0.30;
const PRICE_OUTPUT_PER_1M = 2.50;

function estimateUsd(int $inChars, int $outChars): float
{
    $in = $inChars / 4;          // ~4 chars/token
    $out = $outChars / 4;

    return ($in / 1_000_000) * PRICE_INPUT_PER_1M
         + ($out / 1_000_000) * PRICE_OUTPUT_PER_1M;
}

beforeEach(function () {
    app()->forgetInstance(GeminiTranslator::class);
    app()->forgetInstance(TranslationCache::class);
});

test('5 KB page costs less than half a cent (estimated)', function () {
    $body = str_repeat('texto medio para el ejemplo. ', 170); // ~5 KB
    $cost = estimateUsd(strlen($body), strlen($body));
    expect($cost)->toBeLessThan(0.005);
});

test('cache hit avoids any Gemini call (no token cost)', function () {
    Http::fake();
    app(TranslationCache::class)->set('Texto cacheado largo', 'en', 'Cached long text');

    $result = app(GeminiTranslator::class)->translateBatch(['Texto cacheado largo'], 'en');

    expect($result)->toBe([0 => 'Cached long text']);
    Http::assertNothingSent();
});

test('Gemini calls counter increments on real API hits', function () {
    Http::fake([
        '*generativelanguage*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '⟦LL:0⟧Translated⟦/LL:0⟧']]]]],
        ], 200),
    ]);

    config(['lingua.gemini_api_key' => 'fake']);
    $before = TranslationCache::readStat(TranslationCache::STATS_CALLS_TOTAL);
    app(GeminiTranslator::class)->translateBatch(['Texto que cuesta tokens'], 'en');
    $after = TranslationCache::readStat(TranslationCache::STATS_CALLS_TOTAL);

    expect($after)->toBeGreaterThanOrEqual($before + 1);
});

test('cache hits counter increments on cache reuse', function () {
    Http::fake();
    $cache = app(TranslationCache::class);
    $cache->set('común', 'en', 'common');

    $before = TranslationCache::readStat(TranslationCache::STATS_HITS_TOTAL);
    app(GeminiTranslator::class)->translateBatch(['común'], 'en');
    $after = TranslationCache::readStat(TranslationCache::STATS_HITS_TOTAL);

    expect($after)->toBe($before + 1);
});

test('full coverage of 50 cached fragments → zero Gemini calls', function () {
    Http::fake();
    $cache = app(TranslationCache::class);
    $texts = [];
    for ($i = 0; $i < 50; $i++) {
        $t = "frase repetida numero {$i}";
        $texts[] = $t;
        $cache->set($t, 'en', "phrase number {$i}");
    }

    $result = app(GeminiTranslator::class)->translateBatch($texts, 'en');
    expect(count($result))->toBe(50);
    Http::assertNothingSent();
});

test('cost estimate scales linearly with character count', function () {
    $a = estimateUsd(1000, 1000);
    $b = estimateUsd(2000, 2000);
    expect(round($b / $a, 1))->toBe(2.0);
});

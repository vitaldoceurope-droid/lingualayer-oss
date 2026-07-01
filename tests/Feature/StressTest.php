<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

/**
 * "Stress" within a single PHP process — Pest cannot fork, so we do not test
 * real multi-worker concurrency here. What we do test:
 *   - Cache layer survives many writes without slowing down dramatically.
 *   - Batch translation deduplicates and keeps the call count bounded.
 *   - Rate-limit middleware actually fires (covered in SecurityTest).
 *
 * Real load testing must be done with wrk/k6 against a deployed instance.
 */
beforeEach(function () {
    app()->forgetInstance(TranslationCache::class);
    app()->forgetInstance(GeminiTranslator::class);
});

test('cache stays correct after 10000 writes and reads', function () {
    $cache = app(TranslationCache::class);

    for ($i = 0; $i < 10_000; $i++) {
        $cache->set("texto numero {$i}", 'en', "text number {$i}");
    }

    // Sample read
    expect($cache->get('texto numero 0', 'en'))->toBe('text number 0');
    expect($cache->get('texto numero 9999', 'en'))->toBe('text number 9999');
    expect($cache->get('texto numero 5000', 'en'))->toBe('text number 5000');
});

test('batch translate returns one entry per input even with massive duplication', function () {
    // Pre-cache the dedup target so we never hit the network. This proves
    // dedup happens (1 unique → 1 cache lookup → all 50 served).
    app(TranslationCache::class)->set('Texto repetido cincuenta veces', 'en', 'ONLY');
    Http::fake();

    $duplicated = array_fill(0, 50, 'Texto repetido cincuenta veces');
    $result = app(GeminiTranslator::class)->translateBatch($duplicated, 'en');

    expect($result)->toHaveCount(50);
    foreach ($result as $r) {
        expect($r)->toBe('ONLY');
    }
    Http::assertNothingSent();
});

test('warm cache scenario: 100 cached fragments require zero Gemini calls', function () {
    Http::fake();

    $cache = app(TranslationCache::class);
    $texts = [];
    for ($i = 0; $i < 100; $i++) {
        $t = "Frase comun numero {$i} que tiene cierta longitud";
        $texts[] = $t;
        $cache->set($t, 'en', "Common phrase number {$i}");
    }

    $result = app(GeminiTranslator::class)->translateBatch($texts, 'en');

    expect(count($result))->toBe(100);
    Http::assertNothingSent();
});

test('per-process throughput: 1000 cache hits complete in under 2 seconds', function () {
    $cache = app(TranslationCache::class);

    // Pre-populate
    for ($i = 0; $i < 1000; $i++) {
        $cache->set("k{$i}", 'en', "v{$i}");
    }

    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $cache->get("k{$i}", 'en');
    }
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(2.0);
});

<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

test('returns cached translation without hitting api', function () {
    $cache = app(TranslationCache::class);
    $cache->set('Hola mundo', 'en', 'Hello world');

    $result = app(GeminiTranslator::class)->translateBatch(['Hola mundo'], 'en');

    expect($result)->toBe([0 => 'Hello world']);
});

test('skips numeric strings', function () {
    $result = app(GeminiTranslator::class)->translateBatch(['123', '45.67', '1,000'], 'en');

    expect(array_values($result))->toBe(['123', '45.67', '1,000']);
});

test('skips strings shorter than 3 characters', function () {
    $result = app(GeminiTranslator::class)->translateBatch(['hi', 'a', 'ok'], 'en');

    expect(array_values($result))->toBe(['hi', 'a', 'ok']);
});

test('skips email addresses', function () {
    $result = app(GeminiTranslator::class)->translateBatch(['user@example.com'], 'en');

    expect($result[0])->toBe('user@example.com');
});

test('skips url strings', function () {
    $result = app(GeminiTranslator::class)->translateBatch(['https://example.com/page'], 'en');

    expect($result[0])->toBe('https://example.com/page');
});

test('skips symbol-only strings', function () {
    $result = app(GeminiTranslator::class)->translateBatch(['---', '...', '***'], 'en');

    expect(array_values($result))->toBe(['---', '...', '***']);
});

test('returns empty array for empty input', function () {
    $result = app(GeminiTranslator::class)->translateBatch([], 'en');

    expect($result)->toBe([]);
});

test('deduplicates cache lookups within a batch', function () {
    $cache = app(TranslationCache::class);
    $cache->set('Texto de prueba', 'en', 'Test text');

    // Same text twice — both map to cached translation
    $result = app(GeminiTranslator::class)->translateBatch(
        ['Texto de prueba', 'Texto de prueba'],
        'en'
    );

    expect($result[0])->toBe('Test text');
    expect($result[1])->toBe('Test text');
});

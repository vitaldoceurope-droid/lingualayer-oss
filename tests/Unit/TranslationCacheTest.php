<?php

use LinguaLayer\Services\TranslationCache;

test('stores and retrieves a translation', function () {
    $cache = app(TranslationCache::class);

    $cache->set('Hola', 'en', 'Hello');

    expect($cache->get('Hola', 'en'))->toBe('Hello');
});

test('returns null for missing translations', function () {
    $cache = app(TranslationCache::class);

    expect($cache->get('texto no cacheado', 'en'))->toBeNull();
});

test('has() returns true for existing entries', function () {
    $cache = app(TranslationCache::class);
    $cache->set('Prueba', 'fr', 'Test');

    expect($cache->has('Prueba', 'fr'))->toBeTrue();
});

test('has() returns false for missing entries', function () {
    $cache = app(TranslationCache::class);

    expect($cache->has('no existe', 'de'))->toBeFalse();
});

test('forget() removes an entry', function () {
    $cache = app(TranslationCache::class);
    $cache->set('Borrar', 'en', 'Delete');
    $cache->forget('Borrar', 'en');

    expect($cache->get('Borrar', 'en'))->toBeNull();
});

test('different languages are stored independently', function () {
    $cache = app(TranslationCache::class);
    $cache->set('Casa', 'en', 'House');
    $cache->set('Casa', 'fr', 'Maison');

    expect($cache->get('Casa', 'en'))->toBe('House');
    expect($cache->get('Casa', 'fr'))->toBe('Maison');
    expect($cache->get('Casa', 'de'))->toBeNull();
});

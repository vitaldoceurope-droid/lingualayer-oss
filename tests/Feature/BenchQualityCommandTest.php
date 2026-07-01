<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

beforeEach(function () {
    app()->forgetInstance(TranslationCache::class);
    app()->forgetInstance(GeminiTranslator::class);
});

test('lingua:bench-quality fails for an unknown target language', function () {
    $exit = Artisan::call('lingua:bench-quality', ['--target' => 'xx']);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No reference translations');
});

test('lingua:bench-quality reports failure when Gemini calls fail', function () {
    Http::fake([
        '*generativelanguage*' => Http::response(['error' => 'boom'], 500),
    ]);

    $exit = Artisan::call('lingua:bench-quality', [
        '--target' => 'fr',
        '--limit' => 2,
        '--threshold' => 0.5,
    ]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Translation failed');
});

test('lingua:bench-quality passes when actual matches reference closely', function () {
    // Pre-populate cache with reference translations for the FIRST TWO dataset
    // items (limit=2 picks them in order). With both pre-cached, the bench
    // never hits the network and similarity is 1.0 for each.
    $cache = app(TranslationCache::class);
    $cache->set('Su cita es mañana a las diez', 'fr', 'Votre rendez-vous est demain à dix heures');
    $cache->set('Añadir al carrito', 'fr', 'Ajouter au panier');

    Http::fake();

    $exit = Artisan::call('lingua:bench-quality', [
        '--target' => 'fr',
        '--limit' => 2,
        '--threshold' => 0.5,
    ]);

    expect($exit)->toBe(0);
});

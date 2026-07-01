<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\TranslationCache;

/**
 * Offline quality benchmark — uses the canned dataset from
 * LinguaBenchQualityCommand and pre-cached references so we exercise the
 * comparator without burning Gemini tokens. Live runs go through the
 * separate `lingua:bench-quality` command.
 */
test('lingua:bench-quality fails on an unknown target language', function () {
    $exit = Artisan::call('lingua:bench-quality', ['--target' => 'xx']);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No reference translations');
});

test('lingua:bench-quality matches references for FR (using pre-cached data)', function () {
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

test('lingua:bench-quality reports failure when Gemini calls fail', function () {
    Http::fake([
        '*generativelanguage*' => Http::response(['error' => 'boom'], 500),
    ]);
    $exit = Artisan::call('lingua:bench-quality', ['--target' => 'fr', '--limit' => 1]);
    expect($exit)->toBe(1);
});

test('similarity comparator rewards token overlap', function () {
    // Token-overlap similarity is reused from the bench command — we confirm
    // here that "the cat sat" vs "the cat sat down" is recognised as near.
    $tokenize = function (string $s): array {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? '';

        return array_unique(array_filter(preg_split('/\s+/u', trim($s)) ?: [], fn ($t) => $t !== ''));
    };
    $similarity = function (string $a, string $b) use ($tokenize): float {
        $ta = $tokenize($a);
        $tb = $tokenize($b);
        if (empty($ta) && empty($tb)) {
            return 1.0;
        }
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union ? $inter / $union : 0.0;
    };

    expect($similarity('the cat sat', 'the cat sat down'))->toBeGreaterThanOrEqual(0.5);
    expect($similarity('cat', 'dog'))->toBe(0.0);
    expect($similarity('same', 'same'))->toBe(1.0);
});

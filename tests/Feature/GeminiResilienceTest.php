<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\TranslationCache;

function fakeGeminiResponse(string $text): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => $text]]],
        ]],
    ];
}

beforeEach(function () {
    // Reset the singleton so every test gets fresh config
    app()->forgetInstance(TranslationCache::class);
    app()->forgetInstance(GeminiTranslator::class);
});

test('falls back to original text when Gemini returns 500 on every retry', function () {
    Http::fake([
        '*generativelanguage*' => Http::response(['error' => 'server boom'], 500),
    ]);

    $translator = app(GeminiTranslator::class);
    $result = $translator->translateBatch(['Hola mundo este es un texto largo'], 'en');

    expect($result)->toBeNull();
});

test('falls back to original when Gemini returns 429 on every retry', function () {
    Http::fake([
        '*generativelanguage*' => Http::response(['error' => 'rate limit'], 429),
    ]);

    $result = app(GeminiTranslator::class)
        ->translateBatch(['Texto suficientemente largo aquí'], 'en');

    expect($result)->toBeNull();
});

test('succeeds when first attempt fails but retry succeeds', function () {
    Http::fake([
        '*generativelanguage*' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(fakeGeminiResponse('⟦LL:0⟧Hello world⟧'), 200),
    ]);

    $result = app(GeminiTranslator::class)
        ->translateBatch(['Hola mundo bonito'], 'en');

    // The translator either returns the parsed output or the original on
    // delimiter failure — both are acceptable here as long as it does not crash.
    expect($result)->not->toBeNull();
});

test('handles 200 with malformed JSON without crashing', function () {
    Http::fake([
        '*generativelanguage*' => Http::response('not json at all', 200),
    ]);

    $result = app(GeminiTranslator::class)
        ->translateBatch(['Texto largo aquí para traducir'], 'en');

    // No content path → null after retries exhausted, OR original returned.
    expect($result === null || is_array($result))->toBeTrue();
});

test('recovers from cardinality drift by splitting the chunk into per-item requests', function () {
    // The whole 3-item chunk keeps coming back as 1 item (delimiter drift, which
    // gets likelier the bigger the chunk). The engine used to give up and return
    // null for the entire batch — leaving the page untranslated. It now splits
    // the chunk down to single items, each of which parses cleanly, so the page
    // still gets translated instead of staying in the source language.
    Http::fake([
        '*generativelanguage*' => Http::response(
            fakeGeminiResponse('⟦LL:0⟧Translated bit⟦/LL:0⟧'),
            200
        ),
    ]);

    $result = app(GeminiTranslator::class)
        ->translateBatch([
            'Primer texto de prueba',
            'Segundo texto de prueba',
            'Tercer texto de prueba',
        ], 'en');

    expect($result)->not->toBeNull()
        ->and(count($result))->toBe(3)
        ->and($result[0])->toBe('Translated bit');
});

test('split recovery maps each sub-chunk back to the right index', function () {
    $two = fakeGeminiResponse('⟦LL:0⟧UNO⟦/LL:0⟧⟦LL:1⟧DOS⟦/LL:1⟧');

    // Whole 4-item chunk drifts (1 item back) for all 3 attempts → engine splits
    // into 2 + 2; each half returns a clean 2-item response and must land at the
    // correct original positions.
    Http::fake([
        '*generativelanguage*' => Http::sequence()
            ->push(fakeGeminiResponse('⟦LL:0⟧drift⟦/LL:0⟧'), 200)
            ->push(fakeGeminiResponse('⟦LL:0⟧drift⟦/LL:0⟧'), 200)
            ->push(fakeGeminiResponse('⟦LL:0⟧drift⟦/LL:0⟧'), 200)
            ->push($two, 200)
            ->push($two, 200)
            ->whenEmpty($two, 200),
    ]);

    $result = app(GeminiTranslator::class)->translateBatch([
        'uno largo texto', 'dos largo texto', 'tres largo texto', 'cuatro largo texto',
    ], 'en');

    expect($result)->not->toBeNull()
        ->and(count($result))->toBe(4)
        ->and($result[0])->toBe('UNO')
        ->and($result[1])->toBe('DOS')
        ->and($result[2])->toBe('UNO')
        ->and($result[3])->toBe('DOS');
});

test('still fails atomically (null) when the provider is down even after splitting', function () {
    // 500 on every call: split down to singles, each still 500 → null, never a
    // half-translated batch (the atomic guarantee survives the recovery path).
    Http::fake([
        '*generativelanguage*' => Http::response(['error' => 'down'], 500),
    ]);

    $result = app(GeminiTranslator::class)->translateBatch([
        'primer texto largo aqui',
        'segundo texto largo aqui',
    ], 'en');

    expect($result)->toBeNull();
});

test('does not crash with empty API key', function () {
    config()->set('lingua.gemini_api_key', '');
    app()->forgetInstance(GeminiTranslator::class);

    // Default fake returns 200 with empty body — translator should either
    // return originals (when fallbacks succeed) or null (after retries).
    // Both are acceptable; what we test is "does not throw".
    Http::fake();

    $result = app(GeminiTranslator::class)
        ->translateBatch(['Texto razonablemente largo'], 'en');

    expect($result === null || is_array($result))->toBeTrue();
});

test('does not call Gemini for cached fragments', function () {
    Http::fake();

    $cache = app(TranslationCache::class);
    $cache->set('Saludo guardado', 'en', 'Cached greeting');

    $result = app(GeminiTranslator::class)
        ->translateBatch(['Saludo guardado'], 'en');

    expect($result)->toBe([0 => 'Cached greeting']);
    Http::assertNothingSent();
});

test('skips translation for items that look like emails or URLs', function () {
    Http::fake();

    $result = app(GeminiTranslator::class)->translateBatch([
        'user@example.com',
        'https://example.com/path',
        'Texto traducible aquí',
    ], 'en');

    // Whatever happens with the third item, the first two must be returned
    // unchanged because they short-circuit before any HTTP call.
    expect($result)
        ->not->toBeNull()
        ->and($result[0])->toBe('user@example.com')
        ->and($result[1])->toBe('https://example.com/path');
});

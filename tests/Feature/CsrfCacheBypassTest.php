<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Services\HtmlTranslator;

/**
 * Pages whose response embeds a CSRF token (hidden _token input or csrf-token
 * meta) MUST NOT be served from the page cache, because the cached HTML
 * carries one specific session's token and feeding it to a different session
 * yields a 419 mismatch on form submit.
 *
 * This is the un-authenticated counterpart of AuthSessionCacheBypassTest:
 * even visitors who haven't logged in yet have their own CSRF token from
 * the moment Laravel boots their session.
 */
beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
    config()->set('queue.default', 'sync');

    $this->mockTranslator = Mockery::mock(HtmlTranslator::class);
    $this->app->instance(HtmlTranslator::class, $this->mockTranslator);
});

test('cached HTML containing _token input is NOT served from page cache', function () {
    Route::middleware(['web'])->get('/csrf-page', fn () => '<html><head></head><body><form><input name="_token" value="ABCDEFG"></form></body></html>');

    $url = url('/csrf-page');
    $pageKey = 'lingua_page_'.md5($url.'en');

    // Pre-populate cache with a stale CSRF token from session "X"
    Cache::driver('array')->put(
        $pageKey,
        '<html><body><form><input name="_token" value="STALE_TOKEN_XYZ"></form></body></html>',
        3600
    );

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><body><form><input name="_token" value="FRESH"></form></body></html>');

    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/csrf-page');

    expect($response->getContent())->not->toContain('STALE_TOKEN_XYZ');
});

test('HTML containing csrf-token meta tag is NOT cached', function () {
    Route::middleware(['web'])->get('/meta-csrf', fn () => '<html><head><meta name="csrf-token" content="META_TOKEN"></head><body>contenido</body></html>');

    $url = url('/meta-csrf');
    $pageKey = 'lingua_page_'.md5($url.'en');

    expect(Cache::driver('array')->get($pageKey))->toBeNull();

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><head><meta name="csrf-token" content="META_TOKEN"></head><body>content</body></html>');

    $this->withUnencryptedCookie('lingua_lang', 'en')->get('/meta-csrf');

    expect(Cache::driver('array')->get($pageKey))->toBeNull();
});

test('HTML without CSRF tokens IS cached normally (control)', function () {
    Route::middleware(['web'])->get('/no-form', fn () => '<html><head><title>Public</title></head><body><h1>Hola</h1></body></html>');

    $url = url('/no-form');
    $pageKey = 'lingua_page_'.md5($url.'en');

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><body><h1>Hello</h1></body></html>');

    $this->withUnencryptedCookie('lingua_lang', 'en')->get('/no-form');

    expect(Cache::driver('array')->get($pageKey))->toBe('<html><body><h1>Hello</h1></body></html>');
});

test('htmlContainsCsrfToken matches both quoting styles and case', function () {
    $variations = [
        '<input name="_token" value="x">',
        "<input name='_token' value='x'>",
        '<INPUT NAME="_TOKEN" VALUE="x">',
        '<meta name="csrf-token" content="x">',
        "<meta name='csrf-token' content='x'>",
    ];

    Route::middleware(['web'])->get('/v/{n}', function (int $n) use ($variations) {
        return '<html><body>'.$variations[$n].'</body></html>';
    });

    foreach (array_keys($variations) as $n) {
        $url = url('/v/'.$n);
        $pageKey = 'lingua_page_'.md5($url.'en');

        $this->mockTranslator->shouldReceive('translate')
            ->once()
            ->andReturnUsing(fn ($html) => $html);

        $this->withUnencryptedCookie('lingua_lang', 'en')->get('/v/'.$n);

        expect(Cache::driver('array')->get($pageKey))->toBeNull();
    }
});

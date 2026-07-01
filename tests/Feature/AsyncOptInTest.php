<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Jobs\TranslatePageJob;
use LinguaLayer\Services\HtmlTranslator;

/**
 * Async page-translation is an EXPLICIT opt-in since 1.6.0. Laravel 11/12
 * default QUEUE_CONNECTION=database, and the old "async whenever queue != sync"
 * heuristic auto-dispatched a background TranslatePageJob on worker-less hosts
 * that never ran it — the page stayed in the source language and the JS
 * "Translating…" banner spun for the full poll window. These lock in the new
 * contract:
 *   - gateway mode → ALWAYS inline (no local worker), even with LINGUA_ASYNC=true.
 *   - standalone   → inline unless LINGUA_ASYNC=true AND a non-sync queue.
 *   - non-200 responses → never translated, cached, or dispatched.
 */
beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
    $this->mockTranslator = Mockery::mock(HtmlTranslator::class);
    $this->app->instance(HtmlTranslator::class, $this->mockTranslator);

    Route::middleware(['web'])->get('/page', fn () => '<html><head><title>X</title></head><body><h1>Hola mundo</h1></body></html>');
});

test('default database queue without LINGUA_ASYNC translates inline — no job, no banner', function () {
    config()->set('queue.default', 'database'); // the Laravel 11/12 default

    $this->mockTranslator->shouldReceive('translate')->once()
        ->andReturn('<html><body><h1>Hello world</h1></body></html>');

    Bus::fake();
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/page');

    Bus::assertNotDispatched(TranslatePageJob::class);
    expect($response->getContent())->not->toContain('lingua-translating');
    expect($response->getContent())->toContain('Hello world');
});

test('gateway mode never goes async even with LINGUA_ASYNC=true on a non-sync queue', function () {
    config()->set('lingua.mode', 'gateway');
    config()->set('lingua.gateway.license_key', 'LL-TEST-KEY');
    config()->set('lingua.async', true);
    config()->set('queue.default', 'database');
    Http::fake(); // the selector's license verify must not hit the network

    $this->mockTranslator->shouldReceive('translate')->once()
        ->andReturn('<html><body><h1>Hello world</h1></body></html>');

    Bus::fake();
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/page');

    Bus::assertNotDispatched(TranslatePageJob::class);
    expect($response->getContent())->not->toContain('lingua-translating');
});

test('standalone + LINGUA_ASYNC=true + non-sync queue DOES go async (banner + job)', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.async', true);
    config()->set('queue.default', 'database');

    // The async path dispatches a job; it must NOT translate inline.
    $this->mockTranslator->shouldReceive('translate')->never();

    Bus::fake();
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/page');

    Bus::assertDispatched(TranslatePageJob::class);
    expect($response->getContent())->toContain('lingua-translating');
});

test('non-200 responses are never translated, cached, or dispatched', function () {
    config()->set('queue.default', 'sync');
    Route::middleware(['web'])->get('/boom', fn () => response(
        '<html><head></head><body><h1>Hola</h1></body></html>',
        500,
        ['Content-Type' => 'text/html; charset=UTF-8']
    ));

    $this->mockTranslator->shouldReceive('translate')->never();

    $pageKey = 'lingua_page_'.md5(url('/boom').'en');

    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/boom');

    expect($response->getStatusCode())->toBe(500);
    expect(Cache::driver('array')->get($pageKey))->toBeNull();
});

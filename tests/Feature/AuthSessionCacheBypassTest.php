<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Jobs\TranslatePageJob;
use LinguaLayer\Services\HtmlTranslator;

/**
 * Guards the "page cache is unsafe for authenticated sessions" contract.
 * Cached HTML carries the originating session's CSRF token; serving it to a
 * different session yields 419 mismatches. The middleware must detect
 * `login_web_*` session keys and bypass page-level cache for those requests.
 */
beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
    config()->set('queue.default', 'sync');

    $this->mockTranslator = Mockery::mock(HtmlTranslator::class);
    $this->app->instance(HtmlTranslator::class, $this->mockTranslator);

    Route::middleware(['web'])->get('/dashboard', fn () => '<html><head><title>X</title></head><body><h1>Hola</h1></body></html>');
});

test('cached page is NOT served when session has login_web_* keys', function () {
    $url = url('/dashboard');
    $pageKey = 'lingua_page_'.md5($url.'en');
    $stale = '<html><head></head><body>STALE-FROM-OTHER-SESSION</body></html>';

    Cache::driver('array')->put($pageKey, $stale, 3600);

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><body>FRESH-INLINE</body></html>');

    $response = $this->withSession(['login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 42])
        ->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/dashboard');

    expect($response->getContent())->not->toContain('STALE-FROM-OTHER-SESSION');
    expect($response->getContent())->toContain('FRESH-INLINE');
});

test('page cache is NOT written when session has login_web_* keys', function () {
    $url = url('/dashboard');
    $pageKey = 'lingua_page_'.md5($url.'en');

    expect(Cache::driver('array')->get($pageKey))->toBeNull();

    $this->mockTranslator->shouldReceive('translate')
        ->andReturn('<html><body>WHATEVER</body></html>');

    $this->withSession(['login_web_xyz' => 1])
        ->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/dashboard');

    expect(Cache::driver('array')->get($pageKey))->toBeNull();
});

test('async mode: TranslatePageJob is NOT queued for authenticated requests', function () {
    config()->set('queue.default', 'database');
    config()->set('lingua.async', true); // async is opt-in since 1.6.0
    Bus::fake();

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><body>INLINE-FALLBACK</body></html>');

    $response = $this->withSession(['login_web_abc' => 7])
        ->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/dashboard');

    Bus::assertNotDispatched(TranslatePageJob::class);
    expect($response->getContent())->toContain('INLINE-FALLBACK');
});

test('without auth keys: page cache IS written normally', function () {
    $url = url('/dashboard');
    $pageKey = 'lingua_page_'.md5($url.'en');

    expect(Cache::driver('array')->get($pageKey))->toBeNull();

    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->andReturn('<html><body>NORMAL-TRANSLATION</body></html>');

    $this->withUnencryptedCookie('lingua_lang', 'en')->get('/dashboard');

    expect(Cache::driver('array')->get($pageKey))
        ->toBe('<html><body>NORMAL-TRANSLATION</body></html>');
});

test('HtmlTranslator IS still invoked for authenticated requests (fragment cache path)', function () {
    $this->mockTranslator->shouldReceive('translate')
        ->once()
        ->withArgs(function ($html, $lang, $reqUrl) {
            return $lang === 'en'
                && str_contains($html, 'Hola')
                && str_contains($reqUrl, '/dashboard');
        })
        ->andReturn('<html><body>OK</body></html>');

    $this->withSession(['login_web_zzz' => 99])
        ->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/dashboard');

    expect(true)->toBeTrue(); // Mockery verifies expectation at teardown
});

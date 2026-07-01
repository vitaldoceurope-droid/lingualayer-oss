<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GatewayClient;

beforeEach(function () {
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_gateway_license_valid');
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_gateway_usage');
});

test('translate posts to /api/v1/translate with X-License-Key header', function () {
    Http::fake([
        '*api/v1/translate' => Http::response([
            'translated' => 'Bonjour',
            'cached' => false,
            'model' => 'gemini-2.5-flash',
        ], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-TEST-KEY', 30, false);
    $result = $client->translate('Hola', 'fr', 'es');

    expect($result)->toBe('Bonjour');
    Http::assertSent(function ($req) {
        return $req->hasHeader('X-License-Key', 'LL-TEST-KEY')
            && str_contains($req->url(), '/api/v1/translate');
    });
});

test('translate returns null on 401', function () {
    Http::fake([
        '*api/v1/translate' => Http::response(['error' => 'invalid_license'], 401),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-INVALID', 30, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('translate returns null on 429', function () {
    Http::fake([
        '*api/v1/translate' => Http::response(['error' => 'quota_exceeded'], 429),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('translate retries on 5xx and returns null after max attempts', function () {
    Http::fake([
        '*api/v1/translate' => Http::response(['error' => 'boom'], 500),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->translate('Hola texto largo', 'fr', 'es'))->toBeNull();
});

test('translateBatch returns translations array indexed by original positions', function () {
    Http::fake([
        '*api/v1/translate-batch' => Http::response([
            'translations' => ['Un', 'Deux', 'Trois'],
            'cached_count' => 1,
            'api_count' => 2,
        ], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    $out = $client->translateBatch(['Uno', 'Dos', 'Tres'], 'fr', 'es');

    expect($out)->toBe([0 => 'Un', 1 => 'Deux', 2 => 'Trois']);
});

test('translateBatch returns null on size mismatch', function () {
    Http::fake([
        '*api/v1/translate-batch' => Http::response([
            'translations' => ['Un'], // expected 3, got 1
        ], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->translateBatch(['a', 'b', 'c'], 'fr', 'es'))->toBeNull();
});

test('verifyLicense caches result for 24h', function () {
    Http::fake([
        '*api/v1/license/verify' => Http::response(['valid' => true, 'plan' => 'pro'], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);

    $first = $client->verifyLicense();
    $second = $client->verifyLicense();

    expect($first)->toBeTrue();
    expect($second)->toBeTrue();
    Http::assertSentCount(1);
});

test('verifyLicense respects grace period during gateway outage', function () {
    // Seed cache with a valid verdict that's "expired" but still in grace
    Cache::driver(config('lingua.cache_driver', 'array'))->put(
        'lingua_gateway_license_valid',
        ['valid' => true, 'expires_at' => time() - 10, 'last_ok_at' => time() - 3600],
        86400 * 30
    );

    Http::fake([
        '*api/v1/license/verify' => Http::response('error', 502),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->verifyLicense())->toBeTrue();
});

test('getAllowedLanguages returns the entitlement advertised by verify', function () {
    Http::fake([
        '*api/v1/license/verify' => Http::response([
            'valid' => true,
            'plan' => 'free',
            'allowed_languages' => ['FR', 'de'],
            'max_languages' => 2,
        ], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);

    // Normalised to lowercase; a single verify call warms the 24h cache.
    expect($client->getAllowedLanguages())->toBe(['fr', 'de'])
        ->and($client->getAllowedLanguages())->toBe(['fr', 'de']);
    Http::assertSentCount(1);
});

test('getAllowedLanguages returns null when the gateway omits the field', function () {
    Http::fake([
        '*api/v1/license/verify' => Http::response(['valid' => true, 'plan' => 'enterprise'], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->getAllowedLanguages())->toBeNull(); // unrestricted / backward compatible
});

test('getAllowedLanguages is null (fail-open) when the gateway is unreachable', function () {
    Http::fake([
        '*api/v1/license/verify' => Http::response('error', 500),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-OK', 30, false);
    expect($client->getAllowedLanguages())->toBeNull();
});

test('isConfigured returns false without license key', function () {
    expect((new GatewayClient('http://localhost:8001', '', 30, false))->isConfigured())->toBeFalse();
});

test('getName returns gateway', function () {
    expect((new GatewayClient('http://x', 'k', 30, false))->getName())->toBe('gateway');
});

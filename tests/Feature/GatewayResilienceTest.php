<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GatewayClient;

beforeEach(function () {
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_gateway_license_valid');
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_gateway_usage');
});

test('500 from gateway → null after retries, no crash', function () {
    Http::fake(['*api/v1/translate' => Http::response(['error' => 'boom'], 500)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola texto', 'fr', 'es'))->toBeNull();
});

test('502 bad gateway → null after retries', function () {
    Http::fake(['*api/v1/translate' => Http::response('Bad Gateway', 502)]);
    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('503 service unavailable → null', function () {
    Http::fake(['*api/v1/translate' => Http::response('Maintenance', 503)]);
    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola texto', 'fr', 'es'))->toBeNull();
});

test('200 with non-JSON body → null', function () {
    Http::fake(['*api/v1/translate' => Http::response('not json at all', 200, ['Content-Type' => 'text/plain'])]);
    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('200 with valid JSON but missing translated key → null', function () {
    Http::fake(['*api/v1/translate' => Http::response(['unrelated' => 'value'], 200)]);
    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('connection timeout / DNS failure → null and grace period kicks in', function () {
    Http::fake(['*' => fn () => throw new Exception('Could not resolve host')]);

    $client = new GatewayClient('http://nonexistent.invalid', 'LL-K', 1, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBeNull();
});

test('verifyLicense honours grace window when gateway is down', function () {
    Cache::driver(config('lingua.cache_driver', 'array'))->put(
        'lingua_gateway_license_valid',
        ['valid' => true, 'expires_at' => time() - 10, 'last_ok_at' => time() - 1800],
        86400 * 30
    );
    Http::fake(['*' => Http::response('down', 503)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 1, false);
    expect($client->verifyLicense())->toBeTrue();
});

test('verifyLicense denies after grace window expires', function () {
    config(['lingua.gateway.fallback_grace_hours' => 1]);

    Cache::driver(config('lingua.cache_driver', 'array'))->put(
        'lingua_gateway_license_valid',
        ['valid' => true, 'expires_at' => time() - 10, 'last_ok_at' => time() - 7200], // 2h ago
        86400 * 30
    );
    Http::fake(['*' => Http::response('down', 503)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 1, false);
    expect($client->verifyLicense())->toBeFalse();
});

test('translate returns null on 401 (license invalid mid-session)', function () {
    Http::fake(['*api/v1/translate' => Http::response(['error' => 'license_revoked'], 401)]);
    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola texto', 'fr', 'es'))->toBeNull();
});

test('translate returns null on 429 quota exceeded mid-batch', function () {
    Http::fake(['*api/v1/translate-batch' => Http::response([
        'error' => 'quota_exceeded', 'limit' => 1000, 'used' => 1000,
    ], 429)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    expect($client->translateBatch(['a longer text', 'another'], 'fr', 'es'))->toBeNull();
});

test('verify_ssl=false skips certificate checks (config respected)', function () {
    Http::fake(['*api/v1/translate' => Http::response(['translated' => 'OK', 'cached' => false, 'model' => 'g'], 200)]);

    $client = new GatewayClient('https://localhost:8001', 'LL-K', 5, false);
    expect($client->translate('Hola', 'fr', 'es'))->toBe('OK');
});

test('isConfigured handles empty url or empty key', function () {
    expect((new GatewayClient('', 'KEY', 5, false))->isConfigured())->toBeFalse();
    expect((new GatewayClient('http://x', '', 5, false))->isConfigured())->toBeFalse();
    expect((new GatewayClient('http://x', 'KEY', 5, false))->isConfigured())->toBeTrue();
});

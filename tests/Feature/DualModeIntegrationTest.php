<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Services\TranslatorFactory;

test('switch standalone → gateway without restarting picks the new driver', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', 'fake-gemini');
    config()->set('lingua.gateway.license_key', '');

    expect(TranslatorFactory::make()->getName())->toBe('gemini-direct');

    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');
    expect(TranslatorFactory::make()->getName())->toBe('gateway');
});

test('switch gateway → standalone without restart picks Gemini', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');
    expect(TranslatorFactory::make()->getName())->toBe('gateway');

    config()->set('lingua.gateway.license_key', '');
    config()->set('lingua.gemini_api_key', 'fake');
    expect(TranslatorFactory::make()->getName())->toBe('gemini-direct');
});

test('forced mode overrides env auto-detection', function () {
    config()->set('lingua.gemini_api_key', 'fake');
    config()->set('lingua.gateway.license_key', 'LL-XXXX-XXXX-XXXX-XXXX');

    config()->set('lingua.mode', 'standalone');
    expect(TranslatorFactory::make()->getName())->toBe('gemini-direct');

    config()->set('lingua.mode', 'gateway');
    expect(TranslatorFactory::make()->getName())->toBe('gateway');
});

test('detectMode returns unconfigured cleanly when both keys empty', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', '');

    expect(TranslatorFactory::detectMode())->toBe('unconfigured');
});

test('TranslatorInterface is bindable in container', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.gemini_api_key', 'fake-gemini');

    app()->forgetInstance(TranslatorInterface::class);
    $resolved = app(TranslatorInterface::class);

    expect($resolved)->toBeInstanceOf(TranslatorInterface::class);
});

test('GatewayClient does NOT fall back to Gemini in gateway mode', function () {
    config()->set('lingua.mode', 'gateway');
    config()->set('lingua.gateway.url', 'http://localhost:8001');
    config()->set('lingua.gateway.license_key', 'LL-X');
    config()->set('lingua.gateway.verify_ssl', false);

    Http::fake(['*api/v1/translate' => Http::response(['error' => 'boom'], 500)]);

    $translator = TranslatorFactory::make();
    expect($translator->getName())->toBe('gateway');

    // When the gateway 500s, we get null — never silently falls back to Gemini
    expect($translator->translate('Hola texto', 'fr', 'es'))->toBeNull();
});

test('lingua:test command identifies the active mode in its output', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.gemini_api_key', 'fake-key-1234567890');
    Http::fake(['*generativelanguage*' => Http::response(['error' => 'no auth'], 401)]);

    Artisan::call('lingua:test');
    $out = Artisan::output();

    expect($out)->toContain('Mode:')->toContain('standalone');
});

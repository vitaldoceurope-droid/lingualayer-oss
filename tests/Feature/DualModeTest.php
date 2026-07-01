<?php

use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Services\GatewayClient;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\PreservingTranslator;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslatorFactory;

test('detectMode returns standalone with only Gemini key', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', 'fake-gemini');
    config()->set('lingua.gateway.license_key', '');

    expect(TranslatorFactory::detectMode())->toBe('standalone');
});

test('detectMode returns gateway with only license key', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');

    expect(TranslatorFactory::detectMode())->toBe('gateway');
});

test('detectMode prefers gateway when both are configured', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', 'fake-gemini');
    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');

    expect(TranslatorFactory::detectMode())->toBe('gateway');
});

test('detectMode returns unconfigured with neither key', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', '');

    expect(TranslatorFactory::detectMode())->toBe('unconfigured');
});

test('forced standalone mode requires Gemini key', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');

    expect(TranslatorFactory::detectMode())->toBe('unconfigured');
});

test('factory returns Gemini driver (preservation-wrapped) in standalone mode', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.gemini_api_key', 'fake-gemini');

    $t = TranslatorFactory::make();
    expect($t)->toBeInstanceOf(PreservingTranslator::class)
        ->and($t->getName())->toBe('gemini-direct')
        ->and($t->inner())->toBeInstanceOf(GeminiTranslator::class);
});

test('factory returns Gateway driver (preservation-wrapped) in gateway mode', function () {
    config()->set('lingua.mode', 'gateway');
    config()->set('lingua.gateway.license_key', 'LL-AAAA-BBBB-CCCC-DDDD');
    config()->set('lingua.gateway.url', 'http://localhost:8001');

    $t = TranslatorFactory::make();
    expect($t)->toBeInstanceOf(PreservingTranslator::class)
        ->and($t->getName())->toBe('gateway')
        ->and($t->inner())->toBeInstanceOf(GatewayClient::class);
});

test('factory throws when nothing is configured', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', '');

    expect(fn () => TranslatorFactory::make())->toThrow(RuntimeException::class);
});

test('GeminiTranslator implements TranslatorInterface', function () {
    expect(new GeminiTranslator(app(TranslationCache::class)))
        ->toBeInstanceOf(TranslatorInterface::class);
});

test('GatewayClient implements TranslatorInterface', function () {
    expect(new GatewayClient('http://x', 'KEY', 30, false))
        ->toBeInstanceOf(TranslatorInterface::class);
});

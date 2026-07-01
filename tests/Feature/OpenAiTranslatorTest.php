<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\OpenAiTranslator;
use LinguaLayer\Services\PreservingTranslator;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslatorFactory;

function fakeOpenAi(string $content): void
{
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $content]]],
        ], 200),
    ]);
}

test('translates a batch through an OpenAI-compatible endpoint', function () {
    config()->set('lingua.openai.api_key', 'sk-test');
    fakeOpenAi('⟦LL:0⟧Hello world⟦/LL:0⟧');

    $t = new OpenAiTranslator(app(TranslationCache::class));

    expect($t->translateBatch(['Hola mundo'], 'en'))->toBe([0 => 'Hello world'])
        ->and($t->getName())->toBe('openai')
        ->and($t->isConfigured())->toBeTrue();
});

test('sends the request to the configured base_url and model', function () {
    config()->set('lingua.openai.api_key', 'sk-test');
    config()->set('lingua.openai.model', 'gpt-4o-mini');
    config()->set('lingua.openai.base_url', 'https://api.example.com/v1');
    fakeOpenAi('⟦LL:0⟧Hello⟦/LL:0⟧');

    (new OpenAiTranslator(app(TranslationCache::class)))->translateBatch(['Hola'], 'en');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.example.com/v1/chat/completions')
        && $request['model'] === 'gpt-4o-mini'
        && $request['messages'][0]['role'] === 'system');
});

test('a self-hosted base_url counts as configured even without an api key', function () {
    config()->set('lingua.openai.api_key', '');
    config()->set('lingua.openai.base_url', 'http://localhost:11434/v1'); // Ollama

    expect((new OpenAiTranslator(app(TranslationCache::class)))->isConfigured())->toBeTrue();
});

test('factory selects the OpenAI driver when provider=openai', function () {
    config()->set('lingua.mode', 'standalone');
    config()->set('lingua.provider', 'openai');
    config()->set('lingua.openai.api_key', 'sk-test');

    $t = TranslatorFactory::make();

    expect($t)->toBeInstanceOf(PreservingTranslator::class)
        ->and($t->getName())->toBe('openai')
        ->and($t->inner())->toBeInstanceOf(OpenAiTranslator::class);
});

test('detectMode is standalone when provider=openai and a key is set', function () {
    config()->set('lingua.mode', 'auto');
    config()->set('lingua.provider', 'openai');
    config()->set('lingua.gemini_api_key', '');
    config()->set('lingua.gateway.license_key', '');
    config()->set('lingua.openai.api_key', 'sk-test');

    expect(TranslatorFactory::detectMode())->toBe('standalone');
});

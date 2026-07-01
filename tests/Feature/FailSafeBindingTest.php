<?php

use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\NullTranslator;
use LinguaLayer\Services\TranslationStore;

test('NullTranslator is a safe no-op', function () {
    $t = new NullTranslator;

    expect($t->translate('Hola', 'fr', 'es'))->toBeNull()
        ->and($t->translateBatch(['a', 'b'], 'fr', 'es'))->toBeNull()
        ->and($t->isConfigured())->toBeFalse()
        ->and($t->getName())->toBe('null');
});

test('container yields a NullTranslator when unconfigured instead of throwing', function () {
    // Strip every credential so detectMode() === 'unconfigured'.
    config([
        'lingua.mode' => 'standalone',
        'lingua.provider' => 'gemini',
        'lingua.gemini_api_key' => '',
        'lingua.openai.api_key' => '',
        'lingua.openai.base_url' => 'https://api.openai.com/v1',
        'lingua.gateway.license_key' => '',
    ]);

    app()->forgetInstance(TranslatorInterface::class);

    $t = app(TranslatorInterface::class); // must NOT throw

    expect($t)->toBeInstanceOf(NullTranslator::class)
        ->and($t->translate('Hola mundo', 'fr', 'es'))->toBeNull();
});

test('HtmlTranslator degrades to null (serves source) when the driver throws', function () {
    $boom = new class implements TranslatorInterface
    {
        public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
        {
            throw new RuntimeException('driver exploded');
        }

        public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
        {
            throw new RuntimeException('driver exploded');
        }

        public function getName(): string
        {
            return 'boom';
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    $html = new HtmlTranslator($boom, app(TranslationStore::class));

    // Must NOT throw — returns null so the host serves the original HTML.
    expect($html->translate('<html><body><p>Hola mundo</p></body></html>', 'fr'))->toBeNull();
});

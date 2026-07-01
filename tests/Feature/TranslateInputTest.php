<?php

use LinguaLayer\Contracts\TranslatorInterface;

test('returns original fields when user lang equals source lang', function () {
    $this->postJson('/lingua/translate-input', [
        'fields' => ['message' => 'Hola mundo'],
        'source_lang' => 'es',
    ])->assertOk()->assertJson(['fields' => ['message' => 'Hola mundo']]);
});

test('returns original fields when fields are empty', function () {
    $this->postJson('/lingua/translate-input', [
        'fields' => [],
        'source_lang' => 'en',
    ])->assertOk()->assertJson(['fields' => []]);
});

test('does not translate excluded system fields', function () {
    $this->postJson('/lingua/translate-input', [
        'fields' => [
            'email' => 'user@example.com',
            '_token' => 'some-csrf-token',
            'password' => 'secret123',
        ],
        'source_lang' => 'en',
    ])->assertOk()->assertJson(['fields' => [
        'email' => 'user@example.com',
        '_token' => 'some-csrf-token',
        'password' => 'secret123',
    ]]);
});

test('does not translate identity fields matching skip patterns', function () {
    // 'firstname' and 'lastname' match the 'name' skip pattern
    $this->postJson('/lingua/translate-input', [
        'fields' => [
            'firstname' => 'John',
            'lastname' => 'Doe',
        ],
        'source_lang' => 'en',
    ])->assertOk()->assertJson(['fields' => [
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]]);
});

test('skips fields with value shorter than 2 characters', function () {
    $this->postJson('/lingua/translate-input', [
        'fields' => ['note' => 'x'],
        'source_lang' => 'en',
    ])->assertOk()->assertJson(['fields' => ['note' => 'x']]);
});

/** A translator that actually "translates" (uppercases) so the field cap is
 *  distinguishable from a null/no-op translator. */
function lingua_fake_uppercase_translator(): TranslatorInterface
{
    return new class implements TranslatorInterface
    {
        public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
        {
            return strtoupper($text);
        }

        public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
        {
            return array_map('strtoupper', array_values($texts));
        }

        public function getName(): string
        {
            return 'fake';
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };
}

test('translate-input translates when within the field cap', function () {
    $this->app->instance(TranslatorInterface::class, lingua_fake_uppercase_translator());
    config()->set('lingua.throttle.max_fields', 5);

    $this->postJson('/lingua/translate-input', [
        'fields' => ['message' => 'hola mundo'],
        'source_lang' => 'fr', // != source (en) → would translate
    ])->assertOk()->assertJson(['fields' => ['message' => 'HOLA MUNDO']]);
});

test('translate-input returns originals when the field cap is exceeded', function () {
    $this->app->instance(TranslatorInterface::class, lingua_fake_uppercase_translator());
    config()->set('lingua.throttle.max_fields', 1);

    // 2 fields > cap of 1 → served back UNCHANGED (never sent to the translator).
    $this->postJson('/lingua/translate-input', [
        'fields' => ['a' => 'hola mundo', 'b' => 'buenos dias'],
        'source_lang' => 'fr',
    ])->assertOk()->assertJson(['fields' => ['a' => 'hola mundo', 'b' => 'buenos dias']]);
});

test('status endpoint returns unknown for non-existent hash', function () {
    $this->getJson('/lingua/status/'.md5('nonexistent'))
        ->assertOk()
        ->assertJson(['status' => 'unknown']);
});

test('quality dashboard requires secret when configured', function () {
    config()->set('lingua.quality_secret', 'my-secret');

    $this->get('/lingua/quality')->assertForbidden();
    $this->get('/lingua/quality?key=my-secret')->assertOk();
});

test('quality dashboard is hidden (404) when no secret is set outside local', function () {
    // Security model (v1.7.0): with no secret, the dashboard is exposed ONLY on
    // a local dev box — never on production/staging/dev, which are often
    // internet-reachable. This guards against unauthenticated info disclosure.
    config()->set('lingua.quality_secret', '');
    $this->app['env'] = 'production';

    $this->get('/lingua/quality')->assertNotFound();
});

test('quality dashboard is open on a local dev box without a secret', function () {
    config()->set('lingua.quality_secret', '');
    $this->app['env'] = 'local';

    $this->get('/lingua/quality')->assertOk();
});

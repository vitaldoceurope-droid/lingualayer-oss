<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationCache;

test('email field never reaches the translator on form submit', function () {
    Http::fake();

    $this->postJson('/lingua/translate-input', [
        'fields' => [
            'email' => 'user@example.com',
            'message' => 'Hola desde la web',
        ],
        'source_lang' => 'fr',
    ])->assertOk();

    // The endpoint should not have called Gemini for the email value at all.
    Http::assertSent(function ($request) {
        return ! str_contains($request->body(), 'user@example.com');
    });
});

test('password field never reaches the translator on form submit', function () {
    Http::fake();

    $this->postJson('/lingua/translate-input', [
        'fields' => [
            'password' => 'sup3r-s3cret-p4ss',
            'note' => 'algo de contexto',
        ],
        'source_lang' => 'fr',
    ])->assertOk();

    Http::assertSent(function ($request) {
        return ! str_contains($request->body(), 'sup3r-s3cret-p4ss');
    });
});

test('CSRF token never reaches the translator on form submit', function () {
    Http::fake();

    $this->postJson('/lingua/translate-input', [
        'fields' => [
            '_token' => 'csrf-deadbeef-1234',
            'message' => 'mensaje normal',
        ],
        'source_lang' => 'fr',
    ])->assertOk();

    Http::assertSent(function ($request) {
        return ! str_contains($request->body(), 'csrf-deadbeef-1234');
    });
});

test('identity fields matching skip patterns never reach the translator', function () {
    Http::fake();

    $this->postJson('/lingua/translate-input', [
        'fields' => [
            'firstname' => 'María José',
            'lastname' => 'García López',
            'message' => 'comentario público',
        ],
        'source_lang' => 'fr',
    ])->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->body();

        return ! str_contains($body, 'María José')
            && ! str_contains($body, 'García López');
    });
});

test('XSS payload in HTML body is preserved as text and not executed', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            return array_map(fn ($t) => $t.' EN', array_values($texts));
        });

    $payload = '<script>alert("xss")</script>';
    $html = '<html><body><p>Comentario: '.htmlspecialchars($payload).'</p></body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    // The escaped payload must remain escaped — translator never re-decodes
    // user input into live markup.
    expect($result)->not->toContain('<script>alert("xss")</script>');
});

test('quality dashboard returns 404 in production when no secret is set', function () {
    config()->set('lingua.quality_secret', '');
    $this->app['env'] = 'production';

    $this->get('/lingua/quality')->assertNotFound();
});

test('quality dashboard returns 403 with wrong secret', function () {
    config()->set('lingua.quality_secret', 'right-secret');

    $this->get('/lingua/quality?key=wrong-secret')->assertForbidden();
});

test('translate-input is rate-limited at 30 requests per minute', function () {
    config()->set('lingua.source_language', 'es');
    config()->set('lingua.throttle.input', 30);
    Http::fake();

    // 30 should pass; the 31st should be throttled
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/lingua/translate-input', [
            'fields' => ['msg' => "ping {$i}"],
            'source_lang' => 'fr',
        ])->assertOk();
    }

    $this->postJson('/lingua/translate-input', [
        'fields' => ['msg' => 'ping 31'],
        'source_lang' => 'fr',
    ])->assertStatus(429);
});

test('translate-dom rejects unsupported target languages', function () {
    Http::fake();

    $this->postJson('/lingua/translate-dom', [
        'fields' => ['a' => 'Hola'],
        'target_lang' => 'xx',
    ])->assertOk()->assertJson(['fields' => []]);

    Http::assertNothingSent();
});

test('cache key separator prevents text+lang boundary collisions', function () {
    // Regression: the helper must not let "ab" + "cen" collide with "abc" + "en".
    $cache = app(TranslationCache::class);
    $cache->set('ab', 'cen', 'collision-A');
    $cache->set('abc', 'en', 'collision-B');

    expect($cache->get('ab', 'cen'))->toBe('collision-A');
    expect($cache->get('abc', 'en'))->toBe('collision-B');
});

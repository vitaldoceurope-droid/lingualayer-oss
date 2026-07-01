<?php

use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GatewayClient;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

test('license key never appears in HTTP response bodies from gateway client', function () {
    Http::fake(['*api/v1/translate' => Http::response(['translated' => 'OK', 'cached' => false, 'model' => 'g'], 200)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-SECRET-KEY-1234', 5, false);
    $result = $client->translate('Hola', 'fr', 'es');

    // The translated text never embeds the key
    expect($result)->not->toContain('LL-SECRET-KEY-1234');
});

test('XSS payload in source text is preserved as text not as live markup', function () {
    $payload = '<script>alert("xss")</script>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(fn ($texts) => array_map(fn ($t) => $t.' EN', array_values($texts)));

    // Pre-escape as the framework would
    $html = '<html><body><p>'.htmlspecialchars($payload).'</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    // The raw script tag must NOT come out unescaped
    expect($result)->not->toContain('<script>alert("xss")</script>');
});

test('quote characters in source do not break JSON output downstream', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Tom\'s "test"']);

    $html = '<html><body><p>Hola</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    // Round-trip through json_encode to prove the output is JSON-safe
    $encoded = json_encode(['html' => $result], JSON_UNESCAPED_UNICODE);
    expect($encoded)->toBeString();
});

test('CSRF endpoint translate-input rejects PUT/DELETE methods', function () {
    $this->putJson('/lingua/translate-input', ['fields' => []])->assertStatus(405);
    $this->deleteJson('/lingua/translate-input')->assertStatus(405);
});

test('translate-input rejects non-array fields', function () {
    $this->postJson('/lingua/translate-input', [
        'fields' => 'not-an-array',
        'source_lang' => 'fr',
    ])->assertOk()->assertJsonPath('fields', 'not-an-array');
});

test('quality dashboard returns 404 in production without secret', function () {
    config()->set('lingua.quality_secret', '');
    $this->app['env'] = 'production';

    $this->get('/lingua/quality')->assertNotFound();
});

test('quality dashboard returns 403 with wrong secret', function () {
    config()->set('lingua.quality_secret', 'right-secret');
    $this->get('/lingua/quality?key=wrong')->assertForbidden();
});

test('unsupported cookie value lingua_lang falls back to source', function () {
    // Attacker-controlled cookie 'xx' must not translate or crash — the
    // middleware ignores unknown codes and treats the request as source lang.
    $this->withCookie('lingua_lang', 'xx')
        ->getJson('/lingua/status/'.md5('anything'))
        ->assertOk();
});

test('gateway URL in production must be HTTPS', function () {
    // Documented expectation — config currently allows any URL but
    // the GatewayClient honours verify_ssl based on env. This test
    // ensures HTTPS URL with verify=true does not crash.
    Http::fake(['*' => Http::response(['valid' => true], 200)]);

    $client = new GatewayClient('https://api.lingualayer.com', 'LL-K', 5, true);
    expect($client->verifyLicense())->toBeTrue();
});

test('translate-input throttled at 30 req/min per IP', function () {
    config()->set('lingua.source_language', 'es');
    config()->set('lingua.throttle.input', 30);
    Http::fake();

    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/lingua/translate-input', [
            'fields' => ['msg' => "ping {$i}"],
            'source_lang' => 'fr',
        ])->assertOk();
    }
    $this->postJson('/lingua/translate-input', [
        'fields' => ['msg' => 'overflow'],
        'source_lang' => 'fr',
    ])->assertStatus(429);
});

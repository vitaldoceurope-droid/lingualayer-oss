<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

test('returns original html when no translatable text found', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldNotReceive('translateBatch');

    $html = '<html><head></head><body><script>var x = 1;</script></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toBe($html);
});

test('returns null when translateBatch signals total failure', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn(null);

    $html = '<html><body><p>Hola mundo</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toBeNull();
});

test('translates paragraph text nodes', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->once()
        ->andReturn([0 => 'Hello world']);

    $html = '<html><body><p>Hola mundo</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Hello world');
    expect($result)->not->toContain('Hola mundo');
});

test('translates heading text nodes', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Welcome']);

    $html = '<html><body><h1>Bienvenido</h1></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Welcome');
});

test('translates placeholder attributes', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Enter your name']);

    $html = '<html><body><form><input type="text" placeholder="Introduce tu nombre"></form></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Enter your name');
});

test('translates content inside nav', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->once()
        ->andReturn([0 => 'Dashboard', 1 => 'My Profile']);

    $html = '<html><body><nav><ul><li><a href="#">Panel</a></li><li><a href="#">Mi Perfil</a></li></ul></nav></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Dashboard');
});

test('translates span and strong tags', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Important text', 1 => 'Small note']);

    $html = '<html><body><p><strong>Texto importante</strong> y <small>nota pequeña</small></p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Important text');
});

test('does not translate content inside script tags', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldNotReceive('translateBatch');

    $html = '<html><body><script>var msg = "do not translate this";</script></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('do not translate this');
});

test('preserves untranslated content outside translatable tags', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Hello']);

    $html = '<html><body><p>Hola</p><div class="keep-this-class">static</div></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('keep-this-class');
});

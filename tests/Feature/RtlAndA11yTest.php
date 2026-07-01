<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

function captureTranslator(array &$bucket): GeminiTranslator
{
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) use (&$bucket) {
            $values = array_values($texts);
            $bucket = array_merge($bucket, $values);

            return array_map(fn ($t) => $t.' EN', $values);
        });

    return $mock;
}

test('Arabic source text reaches the translator without corruption', function () {
    $bucket = [];
    $html = '<html><body><p>مرحبا بكم في الموقع</p></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('مرحبا بكم في الموقع');
});

test('Hebrew source is captured byte-for-byte', function () {
    $bucket = [];
    $html = '<html><body><p>ברוכים הבאים</p></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('ברוכים הבאים');
});

test('mixed LTR + RTL on same page processes both', function () {
    $bucket = [];
    $html = '<html><body><p>Bienvenido</p><p>مرحبا</p><p>Welcome</p></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)
        ->toContain('Bienvenido')
        ->toContain('مرحبا')
        ->toContain('Welcome');
});

test('aria-label is translated', function () {
    $bucket = [];
    $html = '<html><body><button aria-label="Cerrar diálogo">X</button></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('Cerrar diálogo');
});

test('aria-description is translated', function () {
    $bucket = [];
    $html = '<html><body><div aria-description="Resumen del producto">…</div></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('Resumen del producto');
});

test('role attribute is preserved (not translated as text)', function () {
    $captured = [];
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) use (&$captured) {
            $captured = array_values($texts);
            // role values like "dialog" should NOT reach the translator
            expect(in_array('dialog', $captured, true))->toBeFalse();
            expect(in_array('button', $captured, true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', $captured);
        });

    $html = '<html><body><div role="dialog"><p>Contenido modal</p></div>'
          .'<div role="button">Acción</div></body></html>';
    (new HtmlTranslator($mock))->translate($html, 'en');
});

test('tabindex values are preserved unchanged', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Click me']);

    $html = '<html><body><button tabindex="3">Pulsar</button></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('tabindex="3"');
});

test('lang attribute on root html element is preserved', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Hello']);

    $html = '<html lang="es"><body><p>Hola</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    // lang is preserved (the middleware would update it elsewhere)
    expect($result)->toContain('lang="es"');
});

test('aria-live regions translate without infinite loops', function () {
    $bucket = [];
    $html = '<html><body><div aria-live="polite"><p>Cargando datos…</p></div></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('Cargando datos…');
});

test('label text inside <label> is translated', function () {
    $bucket = [];
    $html = '<html><body><label for="name">Tu nombre</label></body></html>';
    (new HtmlTranslator(captureTranslator($bucket)))->translate($html, 'en');

    expect($bucket)->toContain('Tu nombre');
});

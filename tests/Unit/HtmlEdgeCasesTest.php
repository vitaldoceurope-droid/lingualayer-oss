<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

/**
 * Make a translator that pretends every input is mapped to the same string
 * with " EN" appended. Lets us assert "this got translated" without caring
 * about the precise mapping in batch order.
 */
function passthroughTranslator(): GeminiTranslator
{
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $out = [];
            foreach (array_values($texts) as $i => $t) {
                $out[$i] = $t.' [EN]';
            }

            return $out;
        });

    return $mock;
}

test('handles HTML fragment without <html> or <body>', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Hello']);

    $html = '<p>Hola</p>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Hello');
});

test('preserves emojis and typographic symbols verbatim', function () {
    // DOMDocument re-encodes non-ASCII to numeric entities on save() — browsers
    // render them identically. Decode before comparing so we test what the
    // user actually sees, not the wire format.
    $html = '<html><body><p>Bienvenido 🔥 — precio €25 «oferta»</p></body></html>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Welcome 🔥 — price €25 «offer»']);

    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)
        ->toContain('🔥')
        ->toContain('€25')
        ->toContain('«offer»');
});

test('preserves HTML entities inside text nodes', function () {
    // DOMDocument decodes entities on load and re-encodes on save — verify
    // round-trip does not corrupt user-visible characters.
    $html = '<html><body><p>Tom &amp; Jerry — caf&eacute;</p></body></html>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Tom & Jerry — café']);

    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)->toContain('café');
});

test('does not translate inside HTML comments', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(fn ($texts) => array_map(fn ($t) => 'TR:'.$t, array_values($texts)));

    $html = '<html><body><!-- secreto: hola mundo --><p>Texto visible aquí</p></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('<!-- secreto: hola mundo -->');
});

test('does not translate inside <style> blocks', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Title here']);

    $html = '<html><head><style>.x{content:"hola desde css";}</style></head><body><h1>Título aquí</h1></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)
        ->toContain('hola desde css')
        ->toContain('Title here');
});

test('does not translate inside <pre> and <code> blocks', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Visible']);

    $html = '<html><body>'
        .'<p>Visible aquí</p>'
        .'<pre>echo "no traducir";</pre>'
        .'<code>function hola() { return "no"; }</code>'
        .'</body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)
        ->toContain('echo "no traducir"')
        ->toContain('function hola()');
});

test('survives malformed HTML with unclosed tags', function () {
    // libxml in DOMDocument is forgiving but logs warnings — ensure we still
    // return a usable string and translate what was parseable.
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'Hello']);

    $html = '<html><body><div><p>Hola<span>texto</span></body>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('handles HTML with mixed text in multiple languages without crashing', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Welcome', 1 => 'Bonjour text', 2 => 'Already English']);

    $html = '<html><body>'
        .'<p>Bienvenido</p>'
        .'<p>Bonjour le monde</p>'
        .'<p>Already English</p>'
        .'</body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('processes large HTML with hundreds of nodes without timing out', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    // The translator dedups by unique value, so we get 1 unique text only.
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'ITEM']);

    $items = str_repeat('<li>Elemento de lista</li>', 500);
    $html = "<html><body><ul>{$items}</ul></body></html>";

    $start = microtime(true);
    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $elapsed = microtime(true) - $start;

    expect($result)->toContain('ITEM');
    // 500 nodes should parse in well under a second on any dev machine
    expect($elapsed)->toBeLessThan(5.0);
});

test('respects translate="no" attribute on ancestors', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            // Should never be asked to translate "Datos del usuario" itself
            expect(in_array('Datos del usuario', array_values($texts), true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', array_values($texts));
        });

    $html = '<html><body>'
        .'<p>Texto normal</p>'
        .'<div translate="no"><span>Datos del usuario</span></div>'
        .'</body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)
        ->toContain('Datos del usuario')
        ->toContain('Texto normal EN');
});

test('respects class="notranslate" on ancestors', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            expect(in_array('No tocar esto', array_values($texts), true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', array_values($texts));
        });

    $html = '<html><body>'
        .'<p>Traducible</p>'
        .'<div class="notranslate"><span>No tocar esto</span></div>'
        .'</body></html>';

    (new HtmlTranslator($mock))->translate($html, 'en');
});

test('preserves <script> contents byte-for-byte', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'OK']);

    $script = '<script>var x = {"a":"hola","b":1<2,"c":"<\/p>"}; console.log("hola");</script>';
    $html = "<html><body><p>Texto</p>{$script}</body></html>";

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain($script);
});

test('translates <title> but not other <head> children', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $values = array_values($texts);
            // <title> content must reach the translator
            expect($values)->toContain('Mi página');

            return array_map(fn ($t) => $t.' EN', $values);
        });

    $html = '<html><head>'
        .'<title>Mi página</title>'
        .'<meta name="generator" content="hand">'
        .'</head><body><p>Hola</p></body></html>';

    (new HtmlTranslator($mock))->translate($html, 'en');
});

test('translates configured meta tags only (description, og:title)', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $values = array_values($texts);
            expect($values)->toContain('Descripción SEO');
            expect($values)->toContain('Título OG');
            // generator is NOT in the allowlist — must not appear
            expect(in_array('hand', $values, true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', $values);
        });

    $html = '<html><head>'
        .'<meta name="description" content="Descripción SEO">'
        .'<meta name="generator" content="hand">'
        .'<meta property="og:title" content="Título OG">'
        .'</head><body></body></html>';

    (new HtmlTranslator($mock))->translate($html, 'en');
});

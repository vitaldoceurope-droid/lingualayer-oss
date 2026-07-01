<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

/**
 * Edge cases that aren't in HtmlEdgeCasesTest. Each test documents WHAT
 * it's verifying and WHY it could plausibly break (the regression we'd
 * catch if it did).
 */
function noopTranslator(): GeminiTranslator
{
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $values = array_values($texts);

            return array_map(fn ($t) => $t.' [TR]', $values);
        });

    return $mock;
}

test('CDATA sections inside script blocks are preserved verbatim', function () {
    // Why: <script><![CDATA[...]]></script> is XHTML; if the protect-extract
    // regex doesn't catch it, DOMDocument would mangle the content.
    $html = '<html><body><p>Texto</p>'
          .'<script>/*<![CDATA[*/var x = "<>&"; /*]]>*/</script>'
          .'</body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)
        ->toContain('CDATA')
        ->toContain('var x = "<>&"');
});

test('nested iframes are preserved without recursion', function () {
    // Why: iframe inside iframe inside iframe. We never recurse into
    // iframe content, but the outer markup must not be corrupted.
    $html = '<html><body>'
          .'<iframe src="a.html"><iframe src="b.html"><iframe src="c.html">fallback</iframe></iframe></iframe>'
          .'</body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)
        ->toContain('a.html')
        ->toContain('b.html')
        ->toContain('c.html');
});

test('compound emoji ZWJ sequences survive round-trip', function () {
    // 👨‍👩‍👧 is U+1F468 + ZWJ + U+1F469 + ZWJ + U+1F467. If any layer
    // re-encodes incorrectly, the family becomes 3 separate emoji.
    $html = '<html><body><p>Familia 👨‍👩‍👧 feliz</p></body></html>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Family 👨‍👩‍👧 happy']);

    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)->toContain('👨‍👩‍👧');
});

test('very long URLs in href are not split or truncated', function () {
    // DOMDocument re-encodes & as &amp; — verify after decoding
    $longUrl = 'https://example.com/path?'.str_repeat('foo=bar&', 80);
    $html = '<html><body><a href="'.$longUrl.'">Click</a><p>Texto</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');
    $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)->toContain($longUrl);
});

test('inline base64 data URLs in src are preserved', function () {
    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
    $html = '<html><body><img src="data:image/png;base64,'.$b64.'" alt="pixel"><p>Texto</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toContain('data:image/png;base64,'.$b64);
});

test('SVG with text nodes is protected, not translated', function () {
    // Why: SVG <text> would be picked up by //text() XPath without protection.
    $svg = '<svg width="100" height="50"><text x="10" y="30">SVG label</text></svg>';
    $html = '<html><body>'.$svg.'<p>Texto fuera</p></body></html>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            // Assertion: SVG label must NOT reach Gemini
            expect(in_array('SVG label', array_values($texts), true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', array_values($texts));
        });

    (new HtmlTranslator($mock))->translate($html, 'en');
});

test('MathML blocks are preserved verbatim', function () {
    $math = '<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi><mo>+</mo><mn>1</mn></math>';
    $html = '<html><body>'.$math.'<p>Texto</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toContain('<math')->toContain('<mi>x</mi>');
});

test('custom elements (web components) are not crashed', function () {
    // DOMDocument warns on unknown elements but should still parse them.
    $html = '<html><body><my-custom-element>Texto custom</my-custom-element><p>Otro</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('extensive data-* attributes are not translated', function () {
    $html = '<html><body>'
          .'<div data-action="save" data-id="42" data-meta="hola_no_traducir" data-config="{}">'
          .'<p>Texto visible</p></div></body></html>';

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            // None of the data-* values must reach the translator
            $values = array_values($texts);
            expect(in_array('hola_no_traducir', $values, true))->toBeFalse();
            expect(in_array('save', $values, true))->toBeFalse();
            expect(in_array('42', $values, true))->toBeFalse();

            return array_map(fn ($t) => $t.' EN', $values);
        });

    (new HtmlTranslator($mock))->translate($html, 'en');
});

test('IE conditional comments are preserved', function () {
    $html = '<html><head><!--[if IE]><link rel="stylesheet" href="ie.css"><![endif]--></head>'
          .'<body><p>Texto</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toContain('[if IE]');
});

test('UTF-8 BOM at start of HTML does not crash', function () {
    $bom = "\xEF\xBB\xBF"; // UTF-8 BOM
    $html = $bom.'<html><body><p>Texto con BOM</p></body></html>';
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('mixed tabs and spaces in indentation do not affect parsing', function () {
    $html = "<html>\n\t<body>\n\t\t<p>Texto</p>\n    <p>Otro</p>\n\t</body>\n</html>";
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('mixed line endings CRLF and LF do not break parsing', function () {
    $html = "<html>\r\n<body>\r\n<p>Texto uno</p>\n<p>Texto dos</p>\r\n</body>\r\n</html>";
    $result = (new HtmlTranslator(noopTranslator()))->translate($html, 'en');

    expect($result)->toBeString()->not->toBe('');
});

test('aria-label and other a11y attrs are translated', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $values = array_values($texts);

            return array_map(fn ($t) => $t.' EN', $values);
        });

    $html = '<html><body><button aria-label="Cerrar menú" title="Cerrar">X</button></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)
        ->toContain('Cerrar menú EN')
        ->toContain('Cerrar EN');
});

test('mixed-language source text is sent verbatim to translator', function () {
    // The translator should receive each unique string once, untouched.
    $captured = [];
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) use (&$captured) {
            $captured = array_values($texts);

            return array_map(fn ($t) => $t.' EN', $captured);
        });

    $html = '<html><body><p>Bienvenido</p><p>Welcome</p><p>مرحبا</p></body></html>';
    (new HtmlTranslator($mock))->translate($html, 'en');

    expect($captured)
        ->toContain('Bienvenido')
        ->toContain('Welcome')
        ->toContain('مرحبا');
});

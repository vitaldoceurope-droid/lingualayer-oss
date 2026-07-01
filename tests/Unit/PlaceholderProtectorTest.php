<?php

use LinguaLayer\Services\PlaceholderProtector;

test('masks and restores a Laravel :placeholder', function () {
    $p = new PlaceholderProtector;
    [$masked, $map] = $p->mask('Bienvenido :name a la plataforma');

    expect($masked)->not->toContain(':name')
        ->and($masked)->toContain('⟦#0⟧')
        ->and($map)->toBe([0 => ':name'])
        ->and($p->restore($masked, $map))->toBe('Bienvenido :name a la plataforma');
});

test('masks curly variables, double-brace and printf tokens', function () {
    $p = new PlaceholderProtector;
    $text = 'Tienes {count} mensajes de {{ user }} con %d puntos';
    [$masked, $map] = $p->mask($text);

    expect($masked)->not->toContain('{count}')
        ->and($masked)->not->toContain('{{ user }}')
        ->and($masked)->not->toContain('%d')
        ->and(array_values($map))->toContain('{count}')
        ->and(array_values($map))->toContain('{{ user }}')
        ->and(array_values($map))->toContain('%d')
        ->and($p->restore($masked, $map))->toBe($text);
});

test('does NOT mistake a percent sign in prose for a printf token', function () {
    $p = new PlaceholderProtector;
    [$masked, $map] = $p->mask('Un 20% completado hoy');

    expect($map)->toBe([])
        ->and($masked)->toBe('Un 20% completado hoy');
});

test('masks inline url and email without touching surrounding text', function () {
    $p = new PlaceholderProtector;
    $text = 'Escribe a soporte@acme.io o visita https://acme.io/ayuda hoy';
    [$masked, $map] = $p->mask($text);

    expect($masked)->not->toContain('soporte@acme.io')
        ->and($masked)->not->toContain('https://acme.io/ayuda')
        ->and($masked)->toStartWith('Escribe a ')
        ->and($masked)->toEndWith(' hoy')
        ->and($p->restore($masked, $map))->toBe($text);
});

test('treats a whole url as one token, not a fake colon placeholder', function () {
    $p = new PlaceholderProtector;
    [, $map] = $p->mask('https://example.com/path');

    expect($map)->toHaveCount(1)
        ->and($map[0])->toBe('https://example.com/path');
});

test('masks configured brand terms verbatim and case-insensitively', function () {
    $p = new PlaceholderProtector(['ViataLing']);
    $text = 'Bienvenido a viataling, el mejor traductor';
    [$masked, $map] = $p->mask($text);

    expect($masked)->not->toContain('viataling')
        ->and($map[0])->toBe('viataling') // original casing preserved on restore
        ->and($p->restore($masked, $map))->toBe($text);
});

test('returns the text unchanged when there is nothing to protect', function () {
    $p = new PlaceholderProtector;
    [$masked, $map] = $p->mask('Hola mundo sin variables');

    expect($masked)->toBe('Hola mundo sin variables')
        ->and($map)->toBe([]);
});

test('allTokensPresent detects a dropped sentinel', function () {
    $p = new PlaceholderProtector;
    [$masked, $map] = $p->mask('Hola :name');

    expect($p->allTokensPresent($masked, $map))->toBeTrue()
        ->and($p->allTokensPresent('Hola (token perdido)', $map))->toBeFalse();
});

test('restore tolerates incidental whitespace inside a sentinel', function () {
    $p = new PlaceholderProtector;
    [, $map] = $p->mask('Hola :name'); // [0 => ':name']

    expect($p->restore('Hello ⟦# 0 ⟧', $map))->toBe('Hello :name');
});

test('masks a #hashtag and restores it verbatim', function () {
    $p = new PlaceholderProtector;
    $text = 'Revisa #ofertas y #black-friday hoy';
    [$masked, $map] = $p->mask($text);

    expect($masked)->not->toContain('#ofertas')
        ->and($masked)->not->toContain('#black-friday')
        ->and(array_values($map))->toContain('#ofertas')
        ->and(array_values($map))->toContain('#black-friday')
        ->and($p->restore($masked, $map))->toBe($text);
});

test('masks currency amounts verbatim so prices are not re-localised', function () {
    $p = new PlaceholderProtector;
    foreach (['Total 1.299,50 €', 'Cuesta $1,299.50', 'Solo 19,99€ hoy', 'Son 100 € exactos'] as $text) {
        [$masked, $map] = $p->mask($text);

        expect($map)->not->toBe([])
            ->and($p->restore($masked, $map))->toBe($text);
    }

    // The masked sentinel replaces the whole amount including its symbol.
    [$masked, $map] = $p->mask('Total 1.299,50 €');
    expect($masked)->toBe('Total ⟦#0⟧')
        ->and($map[0])->toBe('1.299,50 €');
});

test('does NOT mask bare counts, years or version strings', function () {
    $p = new PlaceholderProtector;
    foreach (['Tienes 3 mensajes nuevos', 'Fundada en 2026', 'Actualiza a la versión 1.4.0'] as $text) {
        [$masked, $map] = $p->mask($text);

        expect($map)->toBe([])
            ->and($masked)->toBe($text);
    }
});

test('honours host-supplied custom preserve patterns', function () {
    $p = new PlaceholderProtector([], ['/\bSKU-\d+\b/']);
    $text = 'El producto SKU-4521 está agotado';
    [$masked, $map] = $p->mask($text);

    expect($masked)->not->toContain('SKU-4521')
        ->and($map[0])->toBe('SKU-4521')
        ->and($p->restore($masked, $map))->toBe($text);
});

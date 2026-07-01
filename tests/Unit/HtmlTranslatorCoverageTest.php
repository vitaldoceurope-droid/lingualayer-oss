<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;

test('translates the document title', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->once()
        ->andReturnUsing(function (array $texts) {
            // Assert title is present in the batch
            expect($texts)->toContain('Panel de administración');

            return array_map(fn ($t) => $t === 'Panel de administración' ? 'Admin dashboard' : $t, array_values($texts));
        });

    $html = '<html><head><title>Panel de administración</title></head><body></body></html>';
    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('<title>Admin dashboard</title>');
});

test('translates meta description and open graph tags', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $map = [
                'Gestión médica integral' => 'End-to-end medical management',
                'Tu clínica en la nube' => 'Your clinic in the cloud',
                'Plataforma clínica moderna' => 'Modern clinical platform',
            ];

            return array_values(array_map(fn ($t) => $map[$t] ?? $t, $texts));
        });

    $html = '<html><head>'
        .'<meta name="description" content="Gestión médica integral">'
        .'<meta property="og:title" content="Tu clínica en la nube">'
        .'<meta property="og:description" content="Plataforma clínica moderna">'
        .'</head><body></body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('End-to-end medical management');
    expect($result)->toContain('Your clinic in the cloud');
    expect($result)->toContain('Modern clinical platform');
});

test('translates aria-label and data-tooltip attributes', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            $map = [
                'Cerrar menú' => 'Close menu',
                'Ver pacientes' => 'View patients',
            ];

            return array_values(array_map(fn ($t) => $map[$t] ?? $t, $texts));
        });

    $html = '<html><body>'
        .'<button aria-label="Cerrar menú">X</button>'
        .'<a href="#" data-tooltip="Ver pacientes">P</a>'
        .'</body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('aria-label="Close menu"');
    expect($result)->toContain('data-tooltip="View patients"');
});

test('respects translate="no" and class="notranslate" subtree opt-outs', function () {
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturnUsing(function (array $texts) {
            // Only "Hola" should appear — the opted-out subtrees must be excluded
            expect($texts)->toBe(['Hola']);

            return ['Hello'];
        });

    $html = '<html><body>'
        .'<p>Hola</p>'
        .'<p translate="no">No tocar esto</p>'
        .'<div class="notranslate"><p>Tampoco esto</p></div>'
        .'<div data-lingua="skip"><p>Ni esto</p></div>'
        .'</body></html>';

    $result = (new HtmlTranslator($mock))->translate($html, 'en');

    expect($result)->toContain('Hello');
    expect($result)->toContain('No tocar esto');
    expect($result)->toContain('Tampoco esto');
    expect($result)->toContain('Ni esto');
});

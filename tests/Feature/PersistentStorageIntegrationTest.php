<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationStore;

beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('PDO driver not available on this host');
    }
    Artisan::call('migrate:fresh', ['--quiet' => true]);
});

test('HtmlTranslator hits the DB before calling Gemini', function () {
    $store = app(TranslationStore::class);
    $store->store('Hola mundo', 'es', 'fr', 'Bonjour le monde');

    // Mock Gemini — must NOT be called because the only text is in the DB
    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldNotReceive('translateBatch');

    $translator = new HtmlTranslator($mock, $store);

    $html = '<html><body><p>Hola mundo</p></body></html>';
    $result = $translator->translate($html, 'fr', 'http://app.test/x');

    expect($result)->toContain('Bonjour le monde');
});

test('HtmlTranslator only sends to Gemini what is missing from the DB', function () {
    $store = app(TranslationStore::class);
    $store->store('Conocido', 'es', 'fr', 'Connu');

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->once()
        ->withArgs(function ($texts, $lang) {
            // Only the unknown text should reach Gemini.
            return $lang === 'fr'
                && count($texts) === 1
                && in_array('Desconocido', $texts, true);
        })
        ->andReturn([0 => 'Inconnu']);

    $translator = new HtmlTranslator($mock, $store);

    $html = '<html><body><p>Conocido</p><p>Desconocido</p></body></html>';
    $result = $translator->translate($html, 'fr', 'http://app.test/page');

    expect($result)->toContain('Connu')->toContain('Inconnu');
});

test('newly translated texts are persisted with metadata', function () {
    $store = app(TranslationStore::class);

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Nouveau']);

    $translator = new HtmlTranslator($mock, $store);
    $translator->translate(
        '<html><body><p>Texto nuevo</p></body></html>',
        'fr',
        'http://app.test/dashboard'
    );

    $row = Translation::where('source_text', 'Texto nuevo')->first();
    expect($row)->not->toBeNull();
    expect($row->translated_text)->toBe('Nouveau');
    expect($row->page_url)->toBe('http://app.test/dashboard');
    expect($row->target_lang)->toBe('fr');
});

test('change detection marks the previous translation obsolete', function () {
    $store = app(TranslationStore::class);
    $page = 'http://app.test/dashboard';
    $store->store('Bienvenido', 'es', 'fr', 'Bienvenue', ['page_url' => $page]);

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')
        ->andReturn([0 => 'Bienvenue dans votre portail']);

    config(['lingua.translations_change_threshold' => 0.4]);

    $translator = new HtmlTranslator($mock, $store);
    $translator->translate(
        '<html><body><p>Bienvenido a tu portal</p></body></html>',
        'fr',
        $page
    );

    $old = Translation::where('source_text', 'Bienvenido')->first();
    expect($old->is_obsolete)->toBeTrue();
});

test('lingua:migrate-cache fails gracefully without table', function () {
    if (Schema::hasTable('lingua_translations')) {
        Schema::drop('lingua_translations');
    }

    $exit = Artisan::call('lingua:migrate-cache', ['--force' => true]);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('store is not available');
});

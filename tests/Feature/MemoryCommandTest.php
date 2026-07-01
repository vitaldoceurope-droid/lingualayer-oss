<?php

use Illuminate\Support\Facades\Artisan;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\TranslationStore;

beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('PDO driver not available on this host');
    }
    Artisan::call('migrate:fresh', ['--quiet' => true]);
});

test('lingua:memory stats runs and reports the active count', function () {
    $store = app(TranslationStore::class);
    $store->store('Hola mundo', 'es', 'fr', 'Bonjour le monde');
    $store->store('Guardar', 'es', 'en', 'Save');

    $this->artisan('lingua:memory', ['action' => 'stats'])
        ->expectsOutputToContain('Active entries:')
        ->assertExitCode(0);
});

test('lingua:memory exports and re-imports the memory (round trip)', function () {
    $store = app(TranslationStore::class);
    $store->store('Hola mundo', 'es', 'fr', 'Bonjour le monde');
    $store->store('Guardar', 'es', 'en', 'Save');

    $file = tempnam(sys_get_temp_dir(), 'lingua_tm_');

    $this->artisan('lingua:memory', ['action' => 'export', 'file' => $file])
        ->assertExitCode(0);

    expect(substr_count((string) file_get_contents($file), "\n"))->toBe(2);

    // Wipe, then restore from the export.
    Translation::query()->delete();
    expect(Translation::count())->toBe(0);

    $this->artisan('lingua:memory', ['action' => 'import', 'file' => $file])
        ->assertExitCode(0);

    expect(Translation::count())->toBe(2)
        ->and($store->find('Hola mundo', 'es', 'fr')?->translated_text)->toBe('Bonjour le monde');

    @unlink($file);
});

test('lingua:memory import skips malformed lines', function () {
    $file = tempnam(sys_get_temp_dir(), 'lingua_tm_');
    file_put_contents($file, implode("\n", [
        json_encode(['source' => 'Hola', 'source_lang' => 'es', 'target_lang' => 'en', 'translated' => 'Hello']),
        'this is not json',
        json_encode(['missing' => 'required fields']),
    ]));

    $this->artisan('lingua:memory', ['action' => 'import', 'file' => $file])
        ->assertExitCode(0);

    expect(Translation::count())->toBe(1);

    @unlink($file);
});

test('lingua:memory export without a file path fails cleanly', function () {
    $this->artisan('lingua:memory', ['action' => 'export'])
        ->assertExitCode(1);
});

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Services\TranslationCache;

test('lingua:test reports cache stats section', function () {
    Cache::driver('array')->put(TranslationCache::STATS_FRAGMENTS_TOTAL, 42);
    Cache::driver('array')->put(TranslationCache::STATS_HITS_TOTAL, 100);
    Cache::driver('array')->put(TranslationCache::STATS_CALLS_TOTAL, 10);

    $exit = Artisan::call('lingua:test');
    $output = Artisan::output();

    expect($output)
        ->toContain('Cache stats')
        ->toContain('Fragments cached')
        ->toContain('Cache coverage');
});

test('lingua:install runs without throwing in non-interactive mode', function () {
    $exit = Artisan::call('lingua:install', ['--no-warm' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('LinguaLayer installed');
});

test('lingua:install can run twice without breaking', function () {
    Artisan::call('lingua:install', ['--no-warm' => true]);
    $exit = Artisan::call('lingua:install', ['--no-warm' => true]);

    expect($exit)->toBe(0);
});

test('lingua:install does not dispatch warm when queue driver is sync', function () {
    config()->set('queue.default', 'sync');

    Artisan::call('lingua:install', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Queue driver is')->toContain('sync');
});

test('lingua:uninstall completes when nothing was installed', function () {
    $exit = Artisan::call('lingua:uninstall', ['--keep-table' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('LinguaLayer files removed');
});

test('lingua:uninstall clears stats counters', function () {
    Cache::driver('array')->put(TranslationCache::STATS_FRAGMENTS_TOTAL, 99);
    Cache::driver('array')->put(TranslationCache::STATS_LAST_WARM, '2026-04-25 10:00:00');

    Artisan::call('lingua:uninstall', ['--keep-table' => true]);

    expect(Cache::driver('array')->get(TranslationCache::STATS_FRAGMENTS_TOTAL))->toBeNull();
    expect(Cache::driver('array')->get(TranslationCache::STATS_LAST_WARM))->toBeNull();
});

test('lingua:uninstall instructs user to manually edit bootstrap/app.php', function () {
    Artisan::call('lingua:uninstall', ['--keep-table' => true]);
    $output = Artisan::output();

    expect($output)
        ->toContain('bootstrap/app.php')
        ->toContain('composer remove lingualayer/lingualayer');
});

test('lingua:fewshot-stats fails gracefully when table does not exist', function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite extension not available on this platform');
    }

    Schema::dropIfExists('lingua_training_samples');

    $exit = Artisan::call('lingua:fewshot-stats');

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('does not exist');
});

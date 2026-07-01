<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use LinguaLayer\Services\TranslationCache;

test('lingua:install runs cleanly', function () {
    $exit = Artisan::call('lingua:install', ['--no-warm' => true]);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('LinguaLayer installed');
});

test('lingua:install is idempotent (run twice no errors)', function () {
    Artisan::call('lingua:install', ['--no-warm' => true]);
    $exit = Artisan::call('lingua:install', ['--no-warm' => true]);
    expect($exit)->toBe(0);
});

test('lingua:install + lingua:uninstall + lingua:install full cycle', function () {
    expect(Artisan::call('lingua:install', ['--no-warm' => true]))->toBe(0);
    expect(Artisan::call('lingua:uninstall', ['--keep-table' => true]))->toBe(0);
    expect(Artisan::call('lingua:install', ['--no-warm' => true]))->toBe(0);
});

test('lingua:uninstall clears stat counters', function () {
    Cache::driver('array')->put(TranslationCache::STATS_FRAGMENTS_TOTAL, 999);
    Cache::driver('array')->put(TranslationCache::STATS_HITS_TOTAL, 10_000);

    Artisan::call('lingua:uninstall', ['--keep-table' => true]);

    expect(Cache::driver('array')->get(TranslationCache::STATS_FRAGMENTS_TOTAL))->toBeNull();
    expect(Cache::driver('array')->get(TranslationCache::STATS_HITS_TOTAL))->toBeNull();
});

test('lingua:uninstall on a never-installed app does not crash', function () {
    $exit = Artisan::call('lingua:uninstall', ['--keep-table' => true]);
    expect($exit)->toBe(0);
});

test('lingua:uninstall prints manual cleanup instructions for bootstrap/app.php', function () {
    Artisan::call('lingua:uninstall', ['--keep-table' => true]);
    $out = Artisan::output();

    expect($out)
        ->toContain('bootstrap/app.php')
        ->toContain('composer remove lingualayer/lingualayer');
});

test('lingua:test reports the cache stats section', function () {
    Cache::driver('array')->put(TranslationCache::STATS_FRAGMENTS_TOTAL, 42);
    Cache::driver('array')->put(TranslationCache::STATS_HITS_TOTAL, 100);
    Cache::driver('array')->put(TranslationCache::STATS_CALLS_TOTAL, 10);

    Artisan::call('lingua:test');
    $out = Artisan::output();

    expect($out)
        ->toContain('Cache stats')
        ->toContain('Fragments cached')
        ->toContain('Cache coverage');
});

test('lingua:install with sync queue does not dispatch warm', function () {
    config()->set('queue.default', 'sync');
    Artisan::call('lingua:install');
    $out = Artisan::output();

    expect($out)->toContain('Queue driver is')->toContain('sync');
});

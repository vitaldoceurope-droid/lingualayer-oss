<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\TranslationStore;

/**
 * Manual schema setup per test. We avoid the RefreshDatabase trait because
 * its setUp runs unconditionally and fails before our pdo_sqlite skip check
 * can fire. With manual `migrate:fresh` we get the exact same isolation
 * (clean DB per test) plus a clean skip when the driver is missing.
 */
beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('PDO driver not available on this host');
    }
    Artisan::call('migrate:fresh', ['--quiet' => true]);
});

test('find returns null when nothing matches', function () {
    $store = app(TranslationStore::class);
    expect($store->find('Hola', 'es', 'fr'))->toBeNull();
});

test('store and find round-trip', function () {
    $store = app(TranslationStore::class);
    $store->store('Hola mundo', 'es', 'fr', 'Bonjour le monde');

    $row = $store->find('Hola mundo', 'es', 'fr');
    expect($row)->not->toBeNull();
    expect($row->translated_text)->toBe('Bonjour le monde');
});

test('find updates last_seen_at and times_used', function () {
    $store = app(TranslationStore::class);
    $store->store('A long enough text for storage', 'es', 'fr', 'Translated');

    $before = $store->find('A long enough text for storage', 'es', 'fr');
    expect($before->times_used)->toBe(1);
    expect($before->last_seen_at)->not->toBeNull();

    $after = $store->find('A long enough text for storage', 'es', 'fr');
    expect($after->times_used)->toBe(2);
});

test('store upserts on duplicate hash', function () {
    $store = app(TranslationStore::class);
    $store->store('Same source', 'es', 'fr', 'Old translation');
    $store->store('Same source', 'es', 'fr', 'New translation');

    $rows = Translation::where('source_text', 'Same source')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->translated_text)->toBe('New translation');
});

test('store resurrects an obsolete row when re-translated', function () {
    $store = app(TranslationStore::class);
    $row = $store->store('Some text', 'es', 'fr', 'Translation');
    $row->markObsolete();
    expect($row->refresh()->is_obsolete)->toBeTrue();

    $store->store('Some text', 'es', 'fr', 'New translation');
    expect($row->refresh()->is_obsolete)->toBeFalse();
});

test('batchFind issues a single SELECT', function () {
    $store = app(TranslationStore::class);
    $store->store('Texto uno', 'es', 'fr', 'Texte un');
    $store->store('Texto dos', 'es', 'fr', 'Texte deux');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $results = $store->batchFind(['Texto uno', 'Texto dos', 'Texto tres'], 'es', 'fr');

    $selects = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with(strtolower(trim($q['query'])), 'select'));
    expect(count($selects))->toBe(1);
    expect($results)->toHaveCount(2);
});

test('batchStore upserts many rows efficiently', function () {
    $store = app(TranslationStore::class);
    $store->batchStore([
        ['source' => 'Uno',   'source_lang' => 'es', 'target_lang' => 'fr', 'translated' => 'Un'],
        ['source' => 'Dos',   'source_lang' => 'es', 'target_lang' => 'fr', 'translated' => 'Deux'],
        ['source' => 'Tres',  'source_lang' => 'es', 'target_lang' => 'fr', 'translated' => 'Trois'],
    ]);

    expect(Translation::count())->toBe(3);
    expect(Translation::where('source_text', 'Dos')->first()->translated_text)->toBe('Deux');
});

test('markObsolete affects only stale rows', function () {
    $store = app(TranslationStore::class);
    $store->store('Stale text', 'es', 'fr', 'Translated stale');
    $store->store('Fresh text', 'es', 'fr', 'Translated fresh');

    Translation::where('source_text', 'Stale text')
        ->update(['last_seen_at' => now()->subDays(45)]);

    $count = $store->markObsolete(30);

    expect($count)->toBe(1);
    expect(Translation::where('source_text', 'Stale text')->first()->is_obsolete)->toBeTrue();
    expect(Translation::where('source_text', 'Fresh text')->first()->is_obsolete)->toBeFalse();
});

test('cleanup deletes obsolete rows past the delete window', function () {
    $store = app(TranslationStore::class);
    $row = $store->store('Disposable', 'es', 'fr', 'Translation');
    Translation::where('id', $row->id)->update([
        'is_obsolete' => true,
        'updated_at' => now()->subDays(120),
    ]);

    $deleted = $store->cleanup(90);
    expect($deleted)->toBe(1);
    expect(Translation::find($row->id))->toBeNull();
});

test('detectChanges finds a similar previous source', function () {
    $store = app(TranslationStore::class);
    $store->store('Bienvenido', 'es', 'fr', 'Bienvenue', [
        'page_url' => 'http://app.test/dashboard',
    ]);

    $similar = $store->detectChanges('Bienvenido a tu portal', 'http://app.test/dashboard', 'fr', 0.4);
    expect($similar)->not->toBeNull();
    expect($similar->source_text)->toBe('Bienvenido');
});

test('detectChanges returns null when source is identical (re-translate, not change)', function () {
    $store = app(TranslationStore::class);
    $store->store('Idéntico', 'es', 'fr', 'Identique', [
        'page_url' => 'http://app.test/page',
    ]);

    $similar = $store->detectChanges('Idéntico', 'http://app.test/page', 'fr', 0.8);
    expect($similar)->toBeNull();
});

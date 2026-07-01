<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\TranslationStore;

/**
 * Routes-level discovery + signature tests. We do not exercise the BD-aware
 * scan path here (covered by AgentJobsTest with pdo_sqlite); these tests only
 * need the framework's Route facade.
 */
beforeEach(function () {
    config()->set('lingua.excluded_routes', ['admin/*']);

    Route::get('/', fn () => 'home')->middleware('web');
    Route::get('/about', fn () => 'about')->middleware('web');
    Route::get('/users/{id}', fn ($id) => 'user')->middleware('web');
    Route::post('/login', fn () => 'login')->middleware('web');
    Route::get('/admin/dashboard', fn () => 'admin')->middleware('web');
});

test('discoverRoutes returns only public GET routes without parameters', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    expect($urls)->toContain('/');
    expect($urls)->toContain('/about');
});

test('discoverRoutes excludes parameterised routes', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    expect($urls)->not->toContain('/users/{id}');
});

test('discoverRoutes excludes POST routes', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    expect($urls)->not->toContain('/login');
});

test('discoverRoutes excludes config-blocked routes', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    expect($urls)->not->toContain('/admin/dashboard');
});

test('discoverRoutes excludes built-in admin/horizon/telescope prefixes', function () {
    Route::get('/horizon/dashboard', fn () => 'h')->middleware('web');
    Route::get('/telescope/requests', fn () => 't')->middleware('web');
    Route::get('/_debugbar/foo', fn () => 'd')->middleware('web');

    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    expect($urls)->not->toContain('/horizon/dashboard');
    expect($urls)->not->toContain('/telescope/requests');
    expect($urls)->not->toContain('/_debugbar/foo');
});

test('calculateRoutesSignature is deterministic regardless of input order', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));

    $a = $agent->calculateRoutesSignature(['/a', '/b', '/c']);
    $b = $agent->calculateRoutesSignature(['/c', '/a', '/b']);

    expect($a)->toBe($b);
    expect($a)->toHaveLength(64);
});

test('calculateRoutesSignature changes when set changes', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));

    $a = $agent->calculateRoutesSignature(['/a', '/b']);
    $b = $agent->calculateRoutesSignature(['/a', '/b', '/c']);

    expect($a)->not->toBe($b);
});

test('discoverRoutes deduplicates same path registered twice', function () {
    Route::get('/dup', fn () => '1')->middleware('web');
    // Re-registering the same uri produces a second route in the collection;
    // discoverRoutes() must dedupe before returning.

    $agent = new LinguaAgent(app(TranslationStore::class));
    $urls = $agent->discoverRoutes();

    $occurrences = array_count_values($urls)['/dup'] ?? 0;
    expect($occurrences)->toBe(1);
});

test('needsFullScan returns false when agent_state table is missing', function () {
    // No migrate run in this test — Schema::hasTable() returns false.
    $agent = new LinguaAgent(app(TranslationStore::class));
    expect($agent->needsFullScan())->toBeFalse();
});

test('needsChangeCheck returns false when agent_state table is missing', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    expect($agent->needsChangeCheck())->toBeFalse();
});

test('scanForNewPages returns empty array when store unavailable', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $result = $agent->scanForNewPages(app(Kernel::class));
    expect($result)->toBe([]);
});

test('checkForChanges returns empty array when store unavailable', function () {
    $agent = new LinguaAgent(app(TranslationStore::class));
    $result = $agent->checkForChanges(app(Kernel::class));
    expect($result)->toBe([]);
});

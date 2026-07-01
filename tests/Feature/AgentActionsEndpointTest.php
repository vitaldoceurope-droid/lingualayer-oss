<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use LinguaLayer\Jobs\LinguaAgentChangeDetectionJob;
use LinguaLayer\Jobs\LinguaAgentQualityCheckJob;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Services\TranslationCache;

/**
 * /lingua/quality/action/{name} endpoints — the dashboard buttons.
 */
beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
});

test('scan endpoint returns 404 in production without secret', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', '');

    $this->post('/lingua/quality/action/scan')->assertNotFound();
});

test('scan endpoint returns 403 with wrong secret', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', 'right');

    $this->post('/lingua/quality/action/scan?key=wrong')->assertStatus(403);
});

test('scan endpoint queues WarmAllPagesJob per target language', function () {
    // Pilar 5.13 follow-up: the Scan button now dispatches WarmAllPagesJob
    // directly (one per target lang) instead of the discovery job, so the
    // dashboard always shows movement even when the BD already has rows.
    $this->app['env'] = 'local';
    Bus::fake();

    $this->post('/lingua/quality/action/scan')
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(WarmAllPagesJob::class);
});

test('check-changes endpoint queues LinguaAgentChangeDetectionJob', function () {
    $this->app['env'] = 'local';
    Bus::fake();

    $this->post('/lingua/quality/action/check-changes')
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(LinguaAgentChangeDetectionJob::class);
});

test('quality-check endpoint queues LinguaAgentQualityCheckJob', function () {
    $this->app['env'] = 'local';
    Bus::fake();

    $this->post('/lingua/quality/action/quality-check')
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(LinguaAgentQualityCheckJob::class);
});

test('clear-cache clears LinguaLayer keys WITHOUT flushing the host cache', function () {
    // Regression for the v1.7.0 security fix: the dashboard must NEVER flush()
    // the whole store (that wipes the host app's own cached data / sessions).
    // It only forgets LinguaLayer's own well-known keys.
    $this->app['env'] = 'local';

    // A host-owned entry that must survive untouched.
    Cache::driver('array')->put('host_important', 'keep-me', 600);
    // A LinguaLayer counter that SHOULD be cleared.
    Cache::driver('array')->put(TranslationCache::STATS_FRAGMENTS_TOTAL, 42, 600);

    $this->post('/lingua/quality/action/clear-cache')
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(Cache::driver('array')->get('host_important'))->toBe('keep-me');
    expect(Cache::driver('array')->get(TranslationCache::STATS_FRAGMENTS_TOTAL))->toBeNull();
});

test('action endpoints are hidden (404) without a secret outside local', function () {
    // Regression: the mutating action surface must not be open on a
    // non-production-but-internet-reachable host (staging/dev) just because the
    // quality secret defaults to empty. Only a genuinely local box is exempt.
    $this->app['env'] = 'staging';
    config()->set('lingua.quality_secret', '');

    $this->post('/lingua/quality/action/clear-cache')->assertNotFound();
    $this->post('/lingua/quality/action/scan')->assertNotFound();
});

test('enable endpoint without table returns 500 with message', function () {
    $this->app['env'] = 'local';

    $this->post('/lingua/quality/action/enable')
        ->assertStatus(500)
        ->assertJson(['ok' => false]);
});

test('disable endpoint without table returns 500 with message', function () {
    $this->app['env'] = 'local';

    $this->post('/lingua/quality/action/disable')
        ->assertStatus(500)
        ->assertJson(['ok' => false]);
});

test('process-queue endpoint returns processed counter', function () {
    $this->app['env'] = 'local';
    config()->set('queue.default', 'sync'); // empty queue, just exercise the path

    $this->post('/lingua/quality/action/process-queue')
        ->assertOk()
        ->assertJsonStructure(['ok', 'processed', 'failed', 'elapsed_ms']);
});

test('process-queue endpoint enforces secret in production', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', 'right');

    $this->post('/lingua/quality/action/process-queue?key=wrong')->assertStatus(403);
});

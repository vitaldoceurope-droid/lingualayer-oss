<?php

use Illuminate\Support\Facades\Artisan;
use LinguaLayer\Models\AgentProgress;
use LinguaLayer\Services\ProgressTracker;

/**
 * ProgressTracker behaviour. Most paths require the lingua_agent_progress
 * table → only run with pdo_sqlite available.
 */
beforeEach(function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite extension not available');
    }
    Artisan::call('migrate:fresh', ['--quiet' => true]);
});

test('initializeForLanguage creates a row with running status', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 10);

    $row = AgentProgress::where('target_lang', 'fr')->first();
    expect($row)->not->toBeNull();
    expect($row->pages_total)->toBe(10);
    expect($row->status)->toBe('running');
    expect($row->started_at)->not->toBeNull();
});

test('initializeForLanguage with zero pages stays idle', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 0);

    expect(AgentProgress::where('target_lang', 'fr')->value('status'))->toBe('idle');
});

test('initializeForLanguage twice resets counters', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 5);
    $tracker->recordPageCompleted('fr', '/a');
    $tracker->recordPageCompleted('fr', '/b');

    $tracker->initializeForLanguage('fr', 8);

    $row = AgentProgress::where('target_lang', 'fr')->first();
    expect($row->pages_translated)->toBe(0);
    expect($row->pages_total)->toBe(8);
});

test('recordPageCompleted increments counter and updates last_page', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 3);
    $tracker->recordPageCompleted('fr', '/about', 12);

    $row = AgentProgress::where('target_lang', 'fr')->first();
    expect($row->pages_translated)->toBe(1);
    expect($row->fragments_translated)->toBe(12);
    expect($row->last_page_completed)->toBe('/about');
});

test('recordPageCompleted at total marks status completed', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 2);
    $tracker->recordPageCompleted('fr', '/a');
    $tracker->recordPageCompleted('fr', '/b');

    $row = AgentProgress::where('target_lang', 'fr')->first();
    expect($row->status)->toBe('completed');
    expect($row->completed_at)->not->toBeNull();
});

test('recordPageFailed increments failed counter', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 3);
    $tracker->recordPageFailed('fr', '/oops');

    $row = AgentProgress::where('target_lang', 'fr')->first();
    expect($row->pages_failed)->toBe(1);
    expect($row->pages_pending)->toBe(2);
});

test('getProgress returns shaped per-language data', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 4);
    $tracker->recordPageCompleted('fr', '/a');

    $info = $tracker->getProgress('fr');
    expect($info['pages_total'])->toBe(4);
    expect($info['pages_translated'])->toBe(1);
    expect($info['percentage'])->toBe(25.0);
    expect($info['status'])->toBe('running');
});

test('getProgress() with null returns all languages', function () {
    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 2);
    $tracker->initializeForLanguage('en', 3);

    $all = $tracker->getProgress();
    expect(array_keys($all))->toContain('fr', 'en');
});

test('getOverallProgress aggregates across configured target languages', function () {
    config()->set('lingua.source_language', 'es');
    config()->set('lingua.supported_languages', [
        'es' => ['name' => 'es'],
        'fr' => ['name' => 'fr'],
        'en' => ['name' => 'en'],
    ]);

    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 4);
    $tracker->initializeForLanguage('en', 6);
    $tracker->recordPageCompleted('fr', '/a');
    $tracker->recordPageCompleted('en', '/b');
    $tracker->recordPageCompleted('en', '/c');

    $o = $tracker->getOverallProgress();
    expect($o['total_translations'])->toBe(10);
    expect($o['completed_translations'])->toBe(3);
    expect($o['percentage'])->toBe(30.0);
    expect($o['languages_target'])->toBe(2);
    expect($o['all_completed'])->toBeFalse();
});

test('all_completed flips true when every language hits 100', function () {
    config()->set('lingua.source_language', 'es');
    config()->set('lingua.supported_languages', [
        'es' => ['name' => 'es'],
        'fr' => ['name' => 'fr'],
    ]);

    $tracker = app(ProgressTracker::class);
    $tracker->initializeForLanguage('fr', 2);
    $tracker->recordPageCompleted('fr', '/a');
    $tracker->recordPageCompleted('fr', '/b');

    $o = $tracker->getOverallProgress();
    expect($o['all_completed'])->toBeTrue();
});

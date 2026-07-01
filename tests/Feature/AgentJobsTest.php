<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Jobs\LinguaAgentChangeDetectionJob;
use LinguaLayer\Jobs\LinguaAgentDiscoveryJob;
use LinguaLayer\Jobs\LinguaAgentQualityCheckJob;
use LinguaLayer\Jobs\SendCompletionNotificationJob;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\ProgressTracker;

beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
});

test('LinguaAgentDiscoveryJob skips when agent disabled', function () {
    config()->set('lingua.agent.enabled', false);
    Bus::fake();

    (new LinguaAgentDiscoveryJob)->handle(
        app(Kernel::class),
        app(LinguaAgent::class),
        app(ProgressTracker::class),
    );

    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('LinguaAgentChangeDetectionJob skips when agent disabled', function () {
    config()->set('lingua.agent.enabled', false);
    Bus::fake();

    (new LinguaAgentChangeDetectionJob)->handle(
        app(Kernel::class),
        app(LinguaAgent::class),
        app(ProgressTracker::class),
    );

    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('LinguaAgentQualityCheckJob skips when agent disabled', function () {
    config()->set('lingua.agent.enabled', false);

    // Should not throw even though tables are missing.
    (new LinguaAgentQualityCheckJob)->handle();

    expect(true)->toBeTrue();
});

test('LinguaAgentQualityCheckJob skips silently when table is missing', function () {
    config()->set('lingua.agent.enabled', true);

    (new LinguaAgentQualityCheckJob)->handle();

    expect(true)->toBeTrue();
});

test('SendCompletionNotificationJob with single language pushes notification', function () {
    $tracker = app(ProgressTracker::class);

    (new SendCompletionNotificationJob('fr', false, ['pages' => 5]))->handle($tracker);

    $events = $tracker->getNotifications(5);
    expect($events)->not->toBeEmpty();
    expect($events[0]['type'])->toBe('completed');
    expect($events[0]['language'])->toBe('fr');
});

test('SendCompletionNotificationJob with all_completed pushes all_completed event', function () {
    $tracker = app(ProgressTracker::class);

    (new SendCompletionNotificationJob(null, true, ['total' => 100]))->handle($tracker);

    $events = $tracker->getNotifications(5);
    expect($events[0]['type'])->toBe('all_completed');
});

test('SendCompletionNotificationJob with webhook channel does not crash on bad URL', function () {
    config()->set('lingua.agent.notification_channel', 'webhook');
    config()->set('lingua.agent.webhook_url', 'http://nonexistent.invalid/');

    $tracker = app(ProgressTracker::class);

    Http::fake([
        '*' => fn () => throw new Exception('connection refused'),
    ]);

    (new SendCompletionNotificationJob('fr', false))->handle($tracker);

    // Notification still goes to the dashboard ring even if webhook fails.
    expect($tracker->getNotifications(1))->not->toBeEmpty();
});

test('LinguaAgentDiscoveryJob skips when not due (state table missing)', function () {
    config()->set('lingua.agent.enabled', true);
    Bus::fake();

    (new LinguaAgentDiscoveryJob)->handle(
        app(Kernel::class),
        app(LinguaAgent::class),
        app(ProgressTracker::class),
    );

    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

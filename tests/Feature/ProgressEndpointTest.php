<?php

/**
 * /lingua/quality/progress JSON endpoint contract tests.
 */
beforeEach(function () {
    config()->set('lingua.cache_driver', 'array');
});

test('progress endpoint returns 404 in production without secret', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', '');

    $this->get('/lingua/quality/progress')->assertNotFound();
});

test('progress endpoint returns 403 in production with wrong secret', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', 'right-key');

    $this->get('/lingua/quality/progress?key=wrong-key')->assertStatus(403);
});

test('progress endpoint returns 200 with valid secret', function () {
    $this->app['env'] = 'production';
    config()->set('lingua.quality_secret', 'right-key');

    $this->get('/lingua/quality/progress?key=right-key')->assertStatus(200);
});

test('progress endpoint returns expected JSON shape', function () {
    $this->app['env'] = 'local'; // no secret needed

    $response = $this->get('/lingua/quality/progress');
    $response->assertStatus(200);

    $json = $response->json();
    expect($json)->toHaveKeys(['agent', 'languages', 'overall', 'notifications', 'generated_at']);
    expect($json['overall'])->toHaveKeys([
        'total_translations',
        'completed_translations',
        'percentage',
        'languages_active',
        'languages_completed',
        'languages_target',
        'all_completed',
    ]);
});

test('progress endpoint returns empty languages map when nothing tracked', function () {
    $this->app['env'] = 'local';

    $json = $this->get('/lingua/quality/progress')->json();
    expect($json['languages'])->toBe([]);
    expect($json['overall']['all_completed'])->toBeFalse();
});

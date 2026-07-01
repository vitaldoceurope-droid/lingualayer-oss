<?php

use Illuminate\Support\Facades\DB;
use LinguaLayer\Services\GeminiTranslator;

beforeEach(function () {
    config()->set('lingua.few_shot_enabled', true);
    config()->set('lingua.few_shot_max_examples', 5);
    config()->set('lingua.few_shot_cache_hours', 24);
});

it('returns empty array when few_shot_enabled is false', function () {
    config()->set('lingua.few_shot_enabled', false);

    $examples = app(GeminiTranslator::class)->getFewShotExamples('en');

    expect($examples)->toBe([]);
});

it('returns empty array when training_samples table has no matching rows', function () {
    $qb = Mockery::mock('Illuminate\Database\Query\Builder');
    $qb->shouldReceive('where')->andReturnSelf();
    $qb->shouldReceive('when')->andReturnSelf();
    $qb->shouldReceive('orderByDesc')->andReturnSelf();
    $qb->shouldReceive('limit')->andReturnSelf();
    $qb->shouldReceive('get')->andReturn(collect([]));

    DB::shouldReceive('table')->with('lingua_training_samples')->andReturn($qb);

    $examples = app(GeminiTranslator::class)->getFewShotExamples('en');

    expect($examples)->toBe([]);
});

it('returns up to few_shot_max_examples from training_samples', function () {
    $rows = collect(array_map(fn ($i) => (object) [
        'source_text' => "Texto de prueba número {$i}",
        'translated_text' => "Test text number {$i}",
    ], range(1, 5)));

    $qb = Mockery::mock('Illuminate\Database\Query\Builder');
    $qb->shouldReceive('where')->andReturnSelf();
    $qb->shouldReceive('when')->andReturnSelf();
    $qb->shouldReceive('orderByDesc')->andReturnSelf();
    $qb->shouldReceive('limit')->andReturnSelf();
    $qb->shouldReceive('get')->andReturn($rows);

    DB::shouldReceive('table')->with('lingua_training_samples')->andReturn($qb);

    config()->set('lingua.few_shot_max_examples', 5);

    $examples = app(GeminiTranslator::class)->getFewShotExamples('en');

    expect($examples)->toHaveCount(5)
        ->and($examples[0])->toHaveKeys(['source', 'target']);
});

it('buildSystemPrompt without examples returns base prompt unchanged', function () {
    $prompt = app(GeminiTranslator::class)->buildSystemPrompt([]);

    expect($prompt)->toContain('traductor profesional')
        ->and($prompt)->not->toContain('Ejemplos de traducciones');
});

it('buildSystemPrompt with examples injects them into the prompt', function () {
    $examples = [
        ['source' => 'Bienvenido al sistema', 'target' => 'Welcome to the system'],
        ['source' => 'Guardar cambios',        'target' => 'Save changes'],
    ];

    $prompt = app(GeminiTranslator::class)->buildSystemPrompt($examples);

    expect($prompt)->toContain('Bienvenido al sistema')
        ->and($prompt)->toContain('Welcome to the system')
        ->and($prompt)->toContain('Guardar cambios')
        ->and($prompt)->toContain('Save changes')
        ->and($prompt)->toContain('Ejemplos de traducciones')
        ->and($prompt)->toContain('traductor profesional');
});

it('getFewShotExamples returns empty array when database throws', function () {
    DB::shouldReceive('table')->andThrow(new RuntimeException('DB connection failed'));

    $examples = app(GeminiTranslator::class)->getFewShotExamples('en');

    expect($examples)->toBe([]);
});

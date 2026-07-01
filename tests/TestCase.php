<?php

namespace LinguaLayer\Tests;

use LinguaLayer\LinguaLayerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LinguaLayerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('lingua.gemini_api_key', 'test-key-not-real');
        $app['config']->set('lingua.gemini_model', 'gemini-2.5-flash');
        $app['config']->set('lingua.source_language', 'es');
        $app['config']->set('lingua.supported_languages', [
            'es' => ['name' => 'Español', 'flag' => '🇪🇸'],
            'en' => ['name' => 'English', 'flag' => '🇬🇧'],
            'fr' => ['name' => 'Français', 'flag' => '🇫🇷'],
        ]);
        $app['config']->set('lingua.cache_driver', 'array');
        $app['config']->set('lingua.excluded_fields', ['password', '_token', 'email']);
        $app['config']->set('lingua.translate_field_patterns.skip', [
            'name', 'firstname', 'lastname',
        ]);

        // SQLite in-memory DB for tests that need the lingua_training_samples table
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}

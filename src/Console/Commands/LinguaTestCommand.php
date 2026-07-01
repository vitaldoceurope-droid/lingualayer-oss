<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Services\GatewayClient;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslatorFactory;

class LinguaTestCommand extends Command
{
    protected $signature = 'lingua:test';

    protected $description = 'Verify LinguaLayer installation, mode, cache and translator';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold> LinguaLayer Installation Test </>');
        $this->line('─────────────────────────────────────');

        $allPassed = true;

        // 1. Detect operating mode (Pilar 3.x — dual-mode awareness)
        $mode = TranslatorFactory::detectMode();
        $modeColor = match ($mode) {
            'standalone' => 'green',
            'gateway' => 'cyan',
            'unconfigured' => 'red',
            default => 'yellow',
        };
        $this->line("<fg={$modeColor};options=bold>Mode:</> <options=bold>{$mode}</>");

        // 2. Mode-specific configuration check
        if ($mode === 'standalone') {
            $apiKey = config('lingua.gemini_api_key', '');
            $this->line('<fg=green>✓</> Gemini API key found: '.substr($apiKey, 0, 10).'...');
            $this->line('<fg=green>✓</> Gemini model: <options=bold>'.config('lingua.gemini_model', 'gemini-2.5-flash').'</>');
        } elseif ($mode === 'gateway') {
            $url = (string) config('lingua.gateway.url', '');
            $licenseKey = (string) config('lingua.gateway.license_key', '');
            $this->line('<fg=green>✓</> Gateway URL: <options=bold>'.$url.'</>');
            $this->line('<fg=green>✓</> License key: '.substr($licenseKey, 0, 10).'...');
        } else {
            $this->line('<fg=red>✗</> Neither LINGUA_GEMINI_KEY nor LINGUA_LICENSE_KEY is configured.');
            $this->line('  Run <options=bold>php artisan lingua:configure</> for an interactive setup.');
            $allPassed = false;
        }

        // 3. Source language + supported languages
        $sourceLang = config('lingua.source_language', 'en');
        $this->line("<fg=green>✓</> Source language: <options=bold>{$sourceLang}</>");
        $supported = config('lingua.supported_languages', []);
        if (empty($supported)) {
            $this->line('<fg=red>✗</> No supported languages configured');
            $allPassed = false;
        } else {
            $this->line('<fg=green>✓</> Supported languages: '.implode(', ', array_keys($supported)));
        }

        // 4. Cache driver
        $driver = config('lingua.cache_driver', 'file');
        try {
            Cache::driver($driver)->put('lingua_install_test', 'ok', 60);
            $val = Cache::driver($driver)->get('lingua_install_test');
            Cache::driver($driver)->forget('lingua_install_test');
            if ($val !== 'ok') {
                throw new \RuntimeException('Cache read/write mismatch');
            }
            $this->line("<fg=green>✓</> Cache driver ({$driver}) read/write OK");
        } catch (\Throwable $e) {
            $this->line("<fg=red>✗</> Cache error ({$driver}): {$e->getMessage()}");
            $allPassed = false;
        }

        // 5. Assets published
        $jsPath = public_path('vendor/lingualayer/lingua.js');
        if (file_exists($jsPath)) {
            $this->line('<fg=green>✓</> lingua.js published to public/vendor/lingualayer/');
        } else {
            $this->line('<fg=yellow>⚠</> lingua.js not found — run: php artisan vendor:publish --tag=lingua-assets');
        }

        // 6. Live translator test
        if ($mode !== 'unconfigured') {
            $this->line('');
            $this->line($mode === 'gateway'
                ? 'Testing Gateway… (live HTTP call)'
                : 'Testing Gemini API… (live API call)');
            try {
                $translator = app(TranslatorInterface::class);

                if ($translator instanceof GatewayClient) {
                    $valid = $translator->verifyLicense();
                    $this->line($valid
                        ? '<fg=green>✓</> License valid'
                        : '<fg=red>✗</> License invalid or Gateway unreachable');
                    $allPassed = $allPassed && $valid;
                }

                $result = $translator->translate('Hola mundo', 'en', $sourceLang);
                if ($result === null) {
                    $this->line('<fg=red>✗</> Translation returned null');
                    $allPassed = false;
                } elseif (strtolower(trim($result)) === 'hola mundo') {
                    $this->line('<fg=yellow>⚠</> Translation returned unchanged — check key/model/quota');
                } else {
                    $this->line("<fg=green>✓</> Translation OK: 'Hola mundo' → '<options=bold>{$result}</>'");
                }
            } catch (\Throwable $e) {
                $this->line('<fg=red>✗</> Translator error: '.$e->getMessage());
                $allPassed = false;
            }
        }

        // 7. Cache stats
        $this->line('');
        $this->line('<fg=blue;options=bold>📊 Cache stats</>');
        $this->line('─────────────────────────────────────');

        $fragments = TranslationCache::readStat(TranslationCache::STATS_FRAGMENTS_TOTAL);
        $pages = TranslationCache::readStat(TranslationCache::STATS_PAGES_TOTAL);
        $hits = TranslationCache::readStat(TranslationCache::STATS_HITS_TOTAL);
        $calls = TranslationCache::readStat(TranslationCache::STATS_CALLS_TOTAL);
        $lastWarm = Cache::driver($driver)->get(TranslationCache::STATS_LAST_WARM);

        $total = $hits + $calls;
        $coverage = $total > 0 ? round(($hits / $total) * 100, 1) : 0.0;

        $this->line("  Fragments cached:        <options=bold>{$fragments}</>");
        $this->line("  Full pages cached:       <options=bold>{$pages}</>");
        $this->line('  Last warm:               <options=bold>'.($lastWarm ?: 'never').'</>');
        $this->line("  Cache coverage:          <options=bold>{$coverage}%</> ({$hits}/{$total} translations served from cache)");
        $this->line("  Gemini calls avoided:    <options=bold>{$hits}</>");

        $this->line('');

        if ($allPassed) {
            $this->line('<fg=green;options=bold>✓ LinguaLayer is ready.</>');
        } else {
            $this->line('<fg=red;options=bold>✗ Some checks failed — see above.</>');
        }

        $this->line('');

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }
}

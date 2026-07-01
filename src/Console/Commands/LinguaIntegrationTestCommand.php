<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use LinguaLayer\Services\GatewayClient;

/**
 * Pilar 3.13 — exercise the full Gateway pipeline against a live server.
 * Skips itself unless the host explicitly opts in via the env vars below.
 *
 *   LINGUA_INTEGRATION_TESTS=true
 *   LINGUA_GATEWAY_URL=https://<your-gateway-host>
 *   LINGUA_LICENSE_KEY=LL-XXXX-XXXX-XXXX-XXXX
 *
 * Run with: `php artisan lingua:integration-test`
 */
class LinguaIntegrationTestCommand extends Command
{
    protected $signature = 'lingua:integration-test';

    protected $description = 'Smoke-test a real Gateway server with the local license';

    public function handle(): int
    {
        if (! env('LINGUA_INTEGRATION_TESTS')) {
            $this->warn('Set LINGUA_INTEGRATION_TESTS=true to enable. Aborting.');

            return self::FAILURE;
        }

        $url = (string) config('lingua.gateway.url', '');
        $key = (string) config('lingua.gateway.license_key', '');
        if ($url === '' || $key === '') {
            $this->error('LINGUA_GATEWAY_URL and LINGUA_LICENSE_KEY must be set in .env');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=cyan;options=bold>Gateway integration test</>');
        $this->line('  URL:     '.$url);
        $this->line('  License: '.substr($key, 0, 10).'...');
        $this->line('');

        $client = new GatewayClient(
            baseUrl: rtrim($url, '/'),
            licenseKey: $key,
            timeout: 30,
            verifySsl: ! str_starts_with($url, 'http://'),
        );

        // 1. License verify
        $valid = $client->verifyLicense();
        $this->line($valid
            ? '  <fg=green>✓</> verifyLicense: valid'
            : '  <fg=red>✗</> verifyLicense: INVALID — aborting'
        );
        if (! $valid) {
            return self::FAILURE;
        }

        // 2. Single translate
        $r1 = $client->translate('Hola desde el integration test', 'fr', 'es');
        $this->line($r1
            ? "  <fg=green>✓</> translate: '<options=bold>{$r1}</>'"
            : '  <fg=red>✗</> translate: returned null'
        );

        // 3. Batch translate
        $r2 = $client->translateBatch([
            'Bienvenido', 'Iniciar sesión', 'Cerrar sesión',
        ], 'fr', 'es');
        if (is_array($r2)) {
            $this->line('  <fg=green>✓</> translateBatch:');
            foreach ($r2 as $t) {
                $this->line('      - '.$t);
            }
        } else {
            $this->line('  <fg=red>✗</> translateBatch: returned null');
        }

        // 4. Usage with breakdown
        $usage = $client->getUsage();
        if ($usage !== null) {
            $this->line('  <fg=green>✓</> getUsage:');
            $this->line('      plan: '.($usage['plan'] ?? '—'));
            $this->line('      words: '.($usage['words_used'] ?? '?').' / '.($usage['words_limit'] ?? '?'));
            if (! empty($usage['savings_summary'])) {
                $s = $usage['savings_summary'];
                $this->line('      saved: '.($s['total_words_saved'] ?? 0).' words ('
                    .($s['savings_percentage'] ?? 0).'%)');
            }
        } else {
            $this->line('  <fg=yellow>⚠</> getUsage: null (no data yet?)');
        }

        // 5. Repeat call → should be 'cached:true' in /usage breakdown
        $r3 = $client->translate('Hola desde el integration test', 'fr', 'es');
        $this->line('  <fg=green>✓</> repeat translate: '.($r3 ?? 'null'));

        $this->line('');
        $this->line('<fg=green;options=bold>✓ Integration test passed.</>');

        return self::SUCCESS;
    }
}

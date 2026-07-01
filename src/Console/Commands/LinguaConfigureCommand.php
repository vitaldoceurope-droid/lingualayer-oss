<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\TranslatorFactory;

/**
 * Interactive setup wizard. Writes .env keys directly so the user goes from
 * "fresh install" to "first translation" without copy-pasting samples.
 */
class LinguaConfigureCommand extends Command
{
    protected $signature = 'lingua:configure';

    protected $description = 'Interactive wizard to choose standalone or gateway mode';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold>╔═══════════════════════════════════════╗</>');
        $this->line('<fg=blue;options=bold>║   LinguaLayer Configuration Wizard    ║</>');
        $this->line('<fg=blue;options=bold>╚═══════════════════════════════════════╝</>');
        $this->line('');

        $current = TranslatorFactory::detectMode();
        if ($current !== 'unconfigured') {
            $this->line("Current mode: <options=bold>{$current}</>");
            if (! $this->confirm('Reconfigure?', false)) {
                $this->info('No changes made.');

                return self::SUCCESS;
            }
            $this->line('');
        }

        $this->line('LinguaLayer can run in two modes:');
        $this->line('');
        $this->line('  <fg=green;options=bold>1) Standalone</> — your own Google Gemini API key');
        $this->line('     • Pay Google directly per token');
        $this->line('     • No fixed monthly fee');
        $this->line('     • You manage the keys');
        $this->line('     • Best for personal projects and development');
        $this->line('');
        $this->line('  <fg=cyan;options=bold>2) Gateway</> — managed LinguaLayer service');
        $this->line('     • Fixed monthly subscription');
        $this->line('     • No API key management');
        $this->line('     • Global cache with NETWORK EFFECT');
        $this->line('     • The more clients use LinguaLayer, the cheaper it gets for everyone');
        $this->line('     • Multi-LLM quality routing');
        $this->line('');

        $choice = $this->choice('Pick a mode', ['1', '2'], '1');

        $result = $choice === '1'
            ? $this->configureStandalone()
            : $this->configureGateway();

        if ($result === self::SUCCESS) {
            $this->configureCommon();
        }

        return $result;
    }

    /**
     * Settings that apply to both modes and that we must never assume on the
     * host's behalf: the source language (defaults to the app's own locale, not
     * a hard-coded guess) and whether to enable the autonomous agent (opt-in —
     * it spends the host's LLM budget on a schedule, so we ask explicitly).
     */
    private function configureCommon(): void
    {
        $this->line('');
        $appLocale = (string) (config('app.locale') ?: 'en');
        $source = strtolower(trim((string) $this->ask(
            'Source language — the language YOUR content is written in (ISO 639-1)',
            $appLocale
        )));
        if ($source === '') {
            $source = $appLocale;
        }

        $enableAgent = $this->confirm(
            'Enable the autonomous agent? It pre-translates your whole site on a '
            .'schedule, spending your LLM budget automatically. You can enable it '
            .'later with LINGUA_AGENT_ENABLED=true instead.',
            false
        );

        $this->writeEnv([
            'LINGUA_SOURCE_LANG' => $source,
            'LINGUA_AGENT_ENABLED' => $enableAgent ? 'true' : 'false',
        ]);

        $this->line('  <fg=green>✓</> Source language set to <options=bold>'.$source.'</>.');
        $this->line('  <fg=green>✓</> Autonomous agent '.($enableAgent ? 'ENABLED.' : 'left OFF (opt-in).'));
    }

    private function configureStandalone(): int
    {
        $this->line('');
        $this->line('<fg=green;options=bold>Standalone setup</>');
        $key = (string) $this->secret('Paste your Gemini API key (visible during setup only)');
        if ($key === '') {
            $this->error('Empty key — aborting.');

            return self::FAILURE;
        }

        $this->writeEnv([
            'LINGUA_MODE' => 'standalone',
            'LINGUA_GEMINI_KEY' => $key,
            'LINGUA_LICENSE_KEY' => '',
        ]);

        $this->line('');
        $this->line('<fg=green>✓ Standalone mode configured.</>');
        $this->line('  Run <options=bold>php artisan lingua:test</> to verify the key works.');

        return self::SUCCESS;
    }

    private function configureGateway(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>Gateway setup</>');

        $url = (string) $this->ask('Gateway URL', 'https://api.lingualayer.com');
        $key = (string) $this->ask('License key (LL-XXXX-XXXX-XXXX-XXXX)');

        if ($key === '' || ! preg_match('/^LL-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/i', $key)) {
            $this->error('License key format invalid (expected LL-XXXX-XXXX-XXXX-XXXX).');

            return self::FAILURE;
        }

        $domain = (string) $this->ask('Domain (optional, leave empty for any)', '');

        // Live verification call
        $this->line('');
        $this->line('Verifying license against '.$url.' ...');
        try {
            $http = Http::timeout(15);
            if (str_starts_with($url, 'http://')) {
                $http = $http->withoutVerifying();
            }
            $resp = $http->post(rtrim($url, '/').'/api/v1/license/verify', array_filter([
                'license_key' => $key,
                'domain' => $domain ?: null,
            ], fn ($v) => $v !== null));

            if (! $resp->successful() || ! ($resp->json('valid') ?? false)) {
                $reason = $resp->json('reason') ?? 'unknown';
                $this->error("License verification failed: {$reason}");
                if (! $this->confirm('Save anyway?', false)) {
                    return self::FAILURE;
                }
            } else {
                $this->line('  <fg=green>✓ License valid</>');
                $this->line('  Plan: <options=bold>'.($resp->json('plan') ?? 'unknown').'</>');
            }
        } catch (\Throwable $e) {
            $this->warn('Could not reach Gateway: '.$e->getMessage());
            if (! $this->confirm('Save anyway?', false)) {
                return self::FAILURE;
            }
        }

        $this->writeEnv([
            'LINGUA_MODE' => 'gateway',
            'LINGUA_GATEWAY_URL' => $url,
            'LINGUA_LICENSE_KEY' => $key,
            'LINGUA_GEMINI_KEY' => '',
            'LINGUA_GATEWAY_VERIFY_SSL' => str_starts_with($url, 'http://') ? 'false' : 'true',
        ]);

        $this->line('');
        $this->line('<fg=green>✓ Gateway mode configured.</>');
        $this->line('  Run <options=bold>php artisan lingua:test</> to verify connectivity.');
        $this->line('');
        $this->line('  💡 <fg=cyan>Network effect:</> Your client is part of the LinguaLayer network.');
        $this->line('     Every translation other clients request first becomes free for you,');
        $this->line('     and yours benefit them in turn. Costs decrease as the network grows.');

        return self::SUCCESS;
    }

    /**
     * Append/update keys in the host's .env. Idempotent — running this
     * twice with the same values produces no duplicate lines.
     */
    private function writeEnv(array $kv): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            $this->warn('No .env file at '.$path.' — creating one.');
            file_put_contents($path, '');
        }

        $contents = (string) file_get_contents($path);
        foreach ($kv as $k => $v) {
            $line = $k.'='.$v;
            if (preg_match("/^{$k}=.*$/m", $contents)) {
                $contents = preg_replace("/^{$k}=.*$/m", $line, $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
        }
        file_put_contents($path, $contents);
    }
}

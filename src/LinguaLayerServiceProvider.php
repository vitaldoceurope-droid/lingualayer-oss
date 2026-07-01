<?php

namespace LinguaLayer;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LinguaLayer\Console\Commands\LinguaAgentCheckChangesCommand;
use LinguaLayer\Console\Commands\LinguaAgentDisableCommand;
use LinguaLayer\Console\Commands\LinguaAgentEnableCommand;
use LinguaLayer\Console\Commands\LinguaAgentProgressCommand;
use LinguaLayer\Console\Commands\LinguaAgentScanCommand;
use LinguaLayer\Console\Commands\LinguaAgentStatusCommand;
use LinguaLayer\Console\Commands\LinguaBenchQualityCommand;
use LinguaLayer\Console\Commands\LinguaConfigureCommand;
use LinguaLayer\Console\Commands\LinguaFewShotStatsCommand;
use LinguaLayer\Console\Commands\LinguaInstallCommand;
use LinguaLayer\Console\Commands\LinguaIntegrationTestCommand;
use LinguaLayer\Console\Commands\LinguaMemoryCommand;
use LinguaLayer\Console\Commands\LinguaMigrateCacheCommand;
use LinguaLayer\Console\Commands\LinguaTestCommand;
use LinguaLayer\Console\Commands\LinguaTestReportCommand;
use LinguaLayer\Console\Commands\LinguaUninstallCommand;
use LinguaLayer\Console\Commands\LinguaWarmCommand;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Http\Middleware\TranslateResponse;
use LinguaLayer\Jobs\CleanupTranslationsJob;
use LinguaLayer\Jobs\LinguaAgentChangeDetectionJob;
use LinguaLayer\Jobs\LinguaAgentDiscoveryJob;
use LinguaLayer\Jobs\LinguaAgentQualityCheckJob;
use LinguaLayer\Services\GatewayClient;
use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\NullTranslator;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslationScorer;
use LinguaLayer\Services\TranslationStore;
use LinguaLayer\Services\TranslatorFactory;

class LinguaLayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/lingua.php',
            'lingua'
        );

        // Apply LINGUA_TARGET_LANGUAGES override: filter the broad default
        // supported_languages list down to what the host actually wants
        // exposed. The source language is always preserved so the selector
        // stays self-consistent even if the user sets a list that omits it.
        $targets = (array) config('lingua.target_languages', []);
        if (! empty($targets)) {
            $supported = (array) config('lingua.supported_languages', []);
            $source = (string) config('lingua.source_language', 'en');
            $allowed = array_values(array_unique(array_merge($targets, [$source])));
            $filtered = array_intersect_key($supported, array_flip($allowed));
            config(['lingua.supported_languages' => $filtered]);
        }

        $this->app->singleton(TranslationCache::class);
        $this->app->singleton(TranslationStore::class);

        // Pilar 3.3: dual-mode resolution. The interface is the canonical
        // binding; concrete drivers are picked by TranslatorFactory based on
        // env config. The legacy GeminiTranslator binding stays for backward
        // compatibility — anyone still resolving it from the container in
        // standalone mode gets exactly the same instance as before.
        $this->app->singleton(TranslatorInterface::class, function () {
            // Fail-safe: an unconfigured install must never throw during
            // container resolution (which happens while constructing the
            // middleware) — that would 500 every request. Fall back to a no-op
            // translator so the host serves original text untouched.
            try {
                return TranslatorFactory::make();
            } catch (\Throwable $e) {
                return new NullTranslator;
            }
        });

        // Backward-compat: hosts that resolve the concrete class directly get
        // a working instance regardless of mode. We do NOT route this through
        // TranslatorFactory because resolving GeminiTranslator must never throw
        // when no key is set — it just won't translate (consistent with pre-3.x).
        $this->app->singleton(GeminiTranslator::class, function ($app) {
            return new GeminiTranslator($app->make(TranslationCache::class));
        });

        // Concrete GatewayClient for the dashboard / direct callers. The
        // mode-aware factory still owns the TranslatorInterface binding —
        // this binding only ensures the dashboard route can pull a client
        // even when the host is in standalone mode.
        $this->app->singleton(GatewayClient::class, function () {
            return new GatewayClient(
                baseUrl: rtrim((string) config('lingua.gateway.url', ''), '/'),
                licenseKey: (string) config('lingua.gateway.license_key', ''),
                timeout: (int) config('lingua.gateway.timeout', 30),
                verifySsl: (bool) config('lingua.gateway.verify_ssl', true),
            );
        });

        $this->app->singleton(HtmlTranslator::class, function ($app) {
            return new HtmlTranslator(
                $app->make(TranslatorInterface::class),
                $app->make(TranslationStore::class),
            );
        });

        $this->app->singleton(TranslationScorer::class);
    }

    public function boot(): void
    {
        $this->registerConfig();
        $this->applyGatewayLanguageEntitlement();
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerSchedule();
    }

    /**
     * In gateway mode, narrow the language selector to the languages the
     * license is entitled to (free = 2, etc.). The gateway advertises them in
     * its /license/verify response; the package only ever NARROWS, never
     * widens, and composes after the host's own LINGUA_TARGET_LANGUAGES filter.
     *
     * Fail-safe by construction: skipped in console (no gateway HTTP during
     * artisan/tests), a no-op when the entitlement is unknown/unrestricted, and
     * wrapped so any error leaves the host's selector untouched. The gateway's
     * TargetLanguageGate remains the authoritative server-side guard.
     */
    private function applyGatewayLanguageEntitlement(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            if (TranslatorFactory::detectMode() !== 'gateway') {
                return;
            }

            $allowed = $this->app->make(GatewayClient::class)->getAllowedLanguages();
            if (empty($allowed)) {
                return; // unrestricted / unknown → leave host config as-is
            }

            $supported = (array) config('lingua.supported_languages', []);
            $source = (string) config('lingua.source_language', 'en');
            $keep = array_values(array_unique(array_merge(
                array_map('strtolower', $allowed),
                [strtolower($source)]
            )));

            $filtered = array_intersect_key($supported, array_flip($keep));
            if ($filtered !== []) {
                config(['lingua.supported_languages' => $filtered]);
            }
        } catch (\Throwable) {
            // Never break the host's selector over an entitlement lookup.
        }
    }

    /**
     * Named rate limiters for the public translation endpoints. Defined as
     * limiters (not inline 'throttle:N,1' strings) so the limits are tunable
     * via config('lingua.throttle.*') and read fresh on every request.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('lingua-input', fn (Request $request) => Limit::perMinute(
            (int) config('lingua.throttle.input', 200)
        )->by($request->ip()));

        RateLimiter::for('lingua-dom', fn (Request $request) => Limit::perMinute(
            (int) config('lingua.throttle.dom', 600)
        )->by($request->ip()));
    }

    private function registerConfig(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lingua.php' => config_path('lingua.php'),
            ], 'lingua-config');

            $this->publishes([
                __DIR__.'/../resources/js/lingua.js' => public_path('vendor/lingualayer/lingua.js'),
            ], 'lingua-assets');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lingua-migrations');
        }
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $router = $this->app->make(Router::class);

        // Production-facing endpoints that must always exist.
        $router->middleware(['web'])
            ->prefix('lingua')
            ->group(__DIR__.'/../routes/lingua.php');

        // Demo page ships only in non-production environments so published
        // packages never expose a test endpoint to real users.
        if (! $this->app->environment('production')) {
            $router->middleware(['web'])
                ->prefix('lingua')
                ->group(__DIR__.'/../routes/demo.php');
        }
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lingua');
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LinguaTestCommand::class,
                LinguaFewShotStatsCommand::class,
                LinguaWarmCommand::class,
                LinguaInstallCommand::class,
                LinguaUninstallCommand::class,
                LinguaBenchQualityCommand::class,
                LinguaMigrateCacheCommand::class,
                LinguaMemoryCommand::class,
                LinguaConfigureCommand::class,
                LinguaIntegrationTestCommand::class,
                LinguaTestReportCommand::class,
                // Agent commands (Fase 5)
                LinguaAgentStatusCommand::class,
                LinguaAgentScanCommand::class,
                LinguaAgentCheckChangesCommand::class,
                LinguaAgentEnableCommand::class,
                LinguaAgentDisableCommand::class,
                LinguaAgentProgressCommand::class,
            ]);
        }
    }

    /**
     * Hook the nightly cleanup + agent jobs into the host app's scheduler.
     * The host must have `php artisan schedule:run` (or schedule:work)
     * running every minute (cron) for any of this to actually fire.
     */
    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            try {
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);

                if (config('lingua.translations_cleanup_enabled', true)) {
                    $schedule->job(new CleanupTranslationsJob)
                        ->dailyAt('04:00')
                        ->name('lingua:cleanup-translations')
                        ->onOneServer()
                        ->withoutOverlapping();
                }

                if (config('lingua.agent.enabled', false)) {
                    $schedule->job(new LinguaAgentDiscoveryJob)
                        ->everySixHours()
                        ->name('lingua:agent-discovery')
                        ->onOneServer()
                        ->withoutOverlapping();

                    $schedule->job(new LinguaAgentChangeDetectionJob)
                        ->hourly()
                        ->name('lingua:agent-change-detection')
                        ->onOneServer()
                        ->withoutOverlapping();

                    $schedule->job(new LinguaAgentQualityCheckJob)
                        ->dailyAt((string) config('lingua.agent.quality_check_at', '02:00'))
                        ->name('lingua:agent-quality')
                        ->onOneServer()
                        ->withoutOverlapping();

                    // Cron-driven, WORKER-LESS pre-warm. Unlike the jobs above
                    // (which enqueue and need a running queue worker), this runs
                    // the warm INLINE inside `schedule:run`, so a single OS/panel
                    // cron line — with no worker at all — keeps every page warm.
                    // Bounded by warm_max_seconds so each minute's run stays
                    // short; it skips already-cached pages (--detect-new), so once
                    // the site is warm it's a fast no-op, and a newly-enabled
                    // language is auto-translated within about a minute.
                    if (config('lingua.agent.auto_warm', true)) {
                        // NOTE: we intentionally do NOT pass --detect-new here.
                        // The warm loop already skips already-cached (page,lang)
                        // pairs per-combo, so each run only translates what's
                        // genuinely missing and stops at the time budget; the
                        // url-level --detect-new pre-filter is redundant and was
                        // mis-reporting "nothing new" across many languages.
                        // NOTE: no ->onOneServer() here. onOneServer needs a
                        // SHARED lock store (redis/memcached/database); with the
                        // default file cache it mis-skips and the scheduler
                        // reports "no commands ready". withoutOverlapping(10) (a
                        // 10-min mutex expiry) is enough to stop a slow warm from
                        // piling up on a single host.
                        $schedule->command('lingua:warm', [
                            '--max-seconds' => (int) config('lingua.agent.warm_max_seconds', 50),
                        ])
                            ->everyMinute()
                            ->name('lingua:auto-warm')
                            ->withoutOverlapping(10);
                    }
                }
            } catch (\Throwable) {
                // Host may not have a Schedule binding (e.g. in unit tests
                // without the Foundation kernel); ignore silently.
            }
        });
    }

    private function registerMiddleware(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        // Exempt lingua_lang from encryption so JS-set plain cookies survive
        EncryptCookies::except('lingua_lang');

        $kernel->appendMiddlewareToGroup('web', TranslateResponse::class);
    }
}

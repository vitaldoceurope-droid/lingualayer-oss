<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Services\TranslationCache;

/**
 * Reverse what lingua:install did. We deliberately do NOT edit the host's
 * bootstrap/app.php — automatic edits to a Laravel host file are too risky
 * (different bootstrap layouts, custom middleware groups, version drift).
 * The user gets the exact lines to remove and removes them in one paste.
 */
class LinguaUninstallCommand extends Command
{
    protected $signature = 'lingua:uninstall
        {--keep-table : Skip the prompt about dropping lingua_training_samples}';

    protected $description = 'Remove published LinguaLayer files, clear caches, and print remaining cleanup steps';

    public function handle(Filesystem $fs): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold> LinguaLayer Uninstall </>');
        $this->line('─────────────────────────────────────');

        // 1. Remove published JS asset
        $jsDir = public_path('vendor/lingualayer');
        if ($fs->isDirectory($jsDir)) {
            $fs->deleteDirectory($jsDir);
            $this->line('  <fg=green>✓</> removed public/vendor/lingualayer/');
        } else {
            $this->line('  <fg=yellow>·</> public/vendor/lingualayer/ already absent');
        }

        // 2. Remove published config
        $configFile = config_path('lingua.php');
        if ($fs->exists($configFile)) {
            $fs->delete($configFile);
            $this->line('  <fg=green>✓</> removed config/lingua.php');
        } else {
            $this->line('  <fg=yellow>·</> config/lingua.php already absent');
        }

        // 3. Clear all LinguaLayer cache entries we know about
        $this->clearLinguaCache();

        // 4. Optionally drop the training samples table
        $this->maybeDropTable();

        // 5. Print the manual steps the host owner must take. We do not edit
        //    bootstrap/app.php — it is too easy to corrupt and varies per host.
        $this->line('');
        $this->line('<fg=yellow;options=bold>Manual steps remaining:</>');
        $this->line('  1. If you registered LinguaLayer middleware manually, remove it from <options=bold>bootstrap/app.php</>:');
        $this->line('       <fg=cyan>$middleware->appendToGroup(\'web\', \\LinguaLayer\\Http\\Middleware\\TranslateResponse::class);</>');
        $this->line('     (Auto-registration via the service provider is removed by composer remove.)');
        $this->line('  2. Remove the package itself:');
        $this->line('       <options=bold>composer remove lingualayer/lingualayer</>');
        $this->line('  3. (Optional) Remove LINGUA_* entries from your .env file.');

        $this->line('');
        $this->line('<fg=green;options=bold>✓ LinguaLayer files removed.</>');
        $this->line('');

        return self::SUCCESS;
    }

    private function clearLinguaCache(): void
    {
        $driver = config('lingua.cache_driver', 'file');

        try {
            $cache = Cache::driver($driver);

            // Counters and well-known indexes — known keys we can target without scanning
            $known = [
                TranslationCache::STATS_FRAGMENTS_TOTAL,
                TranslationCache::STATS_PAGES_TOTAL,
                TranslationCache::STATS_HITS_TOTAL,
                TranslationCache::STATS_CALLS_TOTAL,
                TranslationCache::STATS_LAST_WARM,
                'lingua_score_index',
            ];
            foreach ($known as $k) {
                $cache->forget($k);
            }

            // Per-fragment and per-page entries are content-addressed (md5) — we
            // cannot enumerate them across drivers. flush() would nuke the host's
            // cache too, so we explicitly do NOT call it. Stale entries simply
            // expire via TTL (default 60 days).
            $this->line('  <fg=green>✓</> cleared LinguaLayer counter and index keys');
            $this->line('    <fg=yellow>·</> per-fragment / per-page entries (lingua_*) will expire by TTL');
            $this->line('      Run <options=bold>php artisan cache:clear</> to drop them immediately.');
        } catch (\Throwable $e) {
            $this->warn("  ✗ cache cleanup error: {$e->getMessage()}");
        }
    }

    private function maybeDropTable(): void
    {
        try {
            if (! Schema::hasTable('lingua_training_samples')) {
                return;
            }
        } catch (\Throwable $e) {
            // No DB connection / driver missing — nothing we can do here, and
            // the user does not want a stack trace during uninstall.
            $this->line('  <fg=yellow>·</> could not check lingua_training_samples table ('.$e->getMessage().')');

            return;
        }

        if ($this->option('keep-table') || ! $this->input->isInteractive()) {
            $this->line('  <fg=yellow>·</> table <options=bold>lingua_training_samples</> kept (use --no-keep-table or run manually)');

            return;
        }

        if (! $this->confirm('Drop table lingua_training_samples? (training data will be lost)', false)) {
            $this->line('  <fg=yellow>·</> table preserved');

            return;
        }

        try {
            Schema::drop('lingua_training_samples');
            $this->line('  <fg=green>✓</> dropped table lingua_training_samples');
        } catch (\Throwable $e) {
            $this->warn("  ✗ could not drop table: {$e->getMessage()}");
        }
    }
}

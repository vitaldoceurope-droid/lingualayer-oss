<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LinguaLayer\Services\TranslationStore;

/**
 * Move existing fragment-cache translations into the persistent BD.
 *
 * Note on the architecture: the cache stores keys as `lingua_<md5(text|lang)>`
 * with the translated string as the value. The source text is NOT stored —
 * the md5 is one-way, so we cannot reconstruct (source_text, source_lang)
 * from a cache entry alone. That makes a 1-to-1 migration from cache to BD
 * impossible by design.
 *
 * This command:
 *   1. Reports what it can see (counts where the driver allows it).
 *   2. Optionally triggers `lingua:warm` so every public page is re-translated
 *      with the persistent store on, populating BD authoritatively.
 *   3. Optionally clears the legacy fragment cache once BD is populated.
 */
class LinguaMigrateCacheCommand extends Command
{
    protected $signature = 'lingua:migrate-cache
        {--force : Skip confirmation prompts}';

    protected $description = 'Move translations from the legacy cache into the persistent lingua_translations table';

    public function handle(TranslationStore $store): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold> LinguaLayer · Cache → DB migration </>');
        $this->line('─────────────────────────────────────');

        if (! $store->isAvailable()) {
            $this->error('Persistent store is not available.');
            $this->line('Run: <options=bold>php artisan migrate</> first to create the lingua_translations table.');

            return self::FAILURE;
        }

        $this->reportCurrentState($store);
        $this->explainLimitation();

        if (! $this->option('force') && ! $this->confirm('Continue with the recommended path (lingua:warm)?', true)) {
            $this->line('Aborted by user.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Running <options=bold>lingua:warm</> — this re-translates every public route with the BD store on.');
        $this->line('All fresh translations land directly in lingua_translations.');
        $this->line('');

        $exit = $this->call('lingua:warm', ['--force' => true]);

        if ($exit !== 0) {
            $this->warn('lingua:warm reported failures. Run it manually to inspect, then re-run this command.');

            return self::FAILURE;
        }

        $this->reportPostState($store);

        if (! $this->option('force')) {
            if ($this->confirm('Clear the legacy fragment cache now? (BD is the source of truth from here on)', false)) {
                $this->clearLegacyCache();
            } else {
                $this->line('Legacy cache kept. It will keep serving fast-path lookups until TTL expires.');
            }
        }

        $this->line('');
        $this->line('<fg=green;options=bold>✓ Migration completed.</>');
        $this->line('');

        return self::SUCCESS;
    }

    private function reportCurrentState(TranslationStore $store): void
    {
        $stats = $store->stats();
        $this->line('  Current BD state:');
        $this->line("    active translations:   <options=bold>{$stats['total_active']}</>");
        $this->line("    obsolete translations: <options=bold>{$stats['total_obsolete']}</>");
        if (! empty($stats['by_language'])) {
            $byLang = collect($stats['by_language'])
                ->map(fn ($n, $l) => "{$l}: {$n}")
                ->implode(', ');
            $this->line("    by language: {$byLang}");
        }
        $this->line('');
    }

    private function explainLimitation(): void
    {
        $this->line('  <fg=yellow>⚠ Architectural note:</>');
        $this->line('    The legacy fragment cache stores keys as md5(text|lang) — one-way.');
        $this->line('    The source text is not stored, so a literal entry-by-entry migration');
        $this->line('    from cache to BD is impossible.');
        $this->line('');
        $this->line('  <fg=green>✓ Recommended path:</>');
        $this->line('    Run <options=bold>lingua:warm --force</> with the BD store on. Every public');
        $this->line('    page is re-translated, and all results are written authoritatively to');
        $this->line('    lingua_translations.');
        $this->line('');
    }

    private function reportPostState(TranslationStore $store): void
    {
        $this->line('');
        $stats = $store->stats();
        $this->line('  BD after warm:');
        $this->line("    active translations:   <options=bold>{$stats['total_active']}</>");
        if (! empty($stats['by_language'])) {
            $byLang = collect($stats['by_language'])
                ->map(fn ($n, $l) => "{$l}: {$n}")
                ->implode(', ');
            $this->line("    by language: {$byLang}");
        }
    }

    /**
     * Best-effort clear of legacy `lingua_*` cache entries. The breadth of what
     * we can do depends on the driver — file/memcached cannot list keys, so
     * we fall back to forgetting the well-known stat counters and the page
     * cache pattern via the database driver if present.
     */
    private function clearLegacyCache(): void
    {
        $driver = config('lingua.cache_driver', 'file');

        if ($driver === 'database') {
            try {
                $deleted = DB::table('cache')->where('key', 'like', 'lingua_%')->delete();
                $this->line("  <fg=green>✓</> cleared {$deleted} legacy lingua_* cache rows from DB cache table");

                return;
            } catch (\Throwable $e) {
                $this->warn("  ✗ DB cache clear failed: {$e->getMessage()}");
            }
        }

        // Other drivers: clear known counter keys; per-fragment entries will
        // expire naturally (default TTL 60 days).
        $known = [
            'lingua_stats:fragments_total',
            'lingua_stats:pages_total',
            'lingua_stats:hits_total',
            'lingua_stats:gemini_calls_total',
        ];
        foreach ($known as $k) {
            Cache::driver($driver)->forget($k);
        }
        $this->line('  <fg=yellow>·</> cleared known counter keys; per-fragment entries will expire by TTL.');
        $this->line('    To purge everything immediately, run: <options=bold>php artisan cache:clear</>');
    }
}

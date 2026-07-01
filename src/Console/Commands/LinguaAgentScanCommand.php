<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\ProgressTracker;

class LinguaAgentScanCommand extends Command
{
    protected $signature = 'lingua:agent:scan {--force : Bypass needsFullScan throttle}';

    protected $description = 'Force a discovery scan + dispatch warm jobs for every target language';

    public function handle(HttpKernel $kernel, LinguaAgent $agent, ProgressTracker $tracker): int
    {
        $this->info('Discovering routes…');
        $routes = $agent->discoverRoutes();
        $this->line('  Found <options=bold>'.count($routes).'</> public GET routes');

        if (! $this->option('force') && ! $agent->needsFullScan()) {
            $this->warn('Scan not due yet. Use --force to override.');

            return self::SUCCESS;
        }

        $this->info('Scanning for pages with new fragments…');
        $newPages = $agent->scanForNewPages($kernel);
        $this->line('  <options=bold>'.count($newPages).'</> page(s) need translation work');

        if (empty($newPages)) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $supported = array_keys((array) config('lingua.supported_languages', []));
        $source = (string) config('lingua.source_language', 'en');
        $targets = array_values(array_diff($supported, [$source]));

        foreach ($targets as $lang) {
            $tracker->initializeForLanguage($lang, count($newPages));
            WarmAllPagesJob::dispatch([$lang], $newPages, false)
                ->onQueue(config('lingua.queue_name', 'lingua'));
            $this->line("  ➜ Queued warm job for <options=bold>{$lang}</>");
        }

        $this->info('Done. Run `lingua:agent:progress` to watch live progress.');

        return self::SUCCESS;
    }
}

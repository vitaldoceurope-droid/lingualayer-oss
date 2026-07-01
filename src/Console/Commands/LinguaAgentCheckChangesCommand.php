<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\ProgressTracker;

class LinguaAgentCheckChangesCommand extends Command
{
    protected $signature = 'lingua:agent:check-changes';

    protected $description = 'Force change detection and queue retranslation jobs for drifted pages';

    public function handle(HttpKernel $kernel, LinguaAgent $agent, ProgressTracker $tracker): int
    {
        $this->info('Checking known pages for content drift…');
        $changed = $agent->checkForChanges($kernel);

        if (empty($changed)) {
            $this->info('  No changes detected.');

            return self::SUCCESS;
        }

        $this->warn('  Detected '.count($changed).' page(s) with new content');

        $supported = array_keys((array) config('lingua.supported_languages', []));
        $source = (string) config('lingua.source_language', 'en');
        $targets = array_values(array_diff($supported, [$source]));

        foreach ($targets as $lang) {
            $tracker->initializeForLanguage($lang, count($changed));
            WarmAllPagesJob::dispatch([$lang], $changed, true)
                ->onQueue(config('lingua.queue_name', 'lingua'));
            $this->line("  ➜ Queued retranslation job for <options=bold>{$lang}</>");
        }

        return self::SUCCESS;
    }
}

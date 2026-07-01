<?php

namespace LinguaLayer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\ProgressTracker;

/**
 * Scheduled hourly. Re-visits known pages, looks for fragments the store has
 * never seen and dispatches retranslation jobs for the affected URLs.
 */
class LinguaAgentChangeDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(HttpKernel $kernel, LinguaAgent $agent, ProgressTracker $tracker): void
    {
        if (! config('lingua.agent.enabled', false)) {
            return;
        }
        if (! $agent->needsChangeCheck()) {
            return;
        }

        $changed = $agent->checkForChanges($kernel);

        Log::channel('single')->info('[LinguaLayer][agent] change-check complete', [
            'changed_pages' => count($changed),
        ]);

        if (empty($changed)) {
            return;
        }

        if (! config('lingua.agent.auto_translate_changes', true)) {
            Log::channel('single')->info('[LinguaLayer][agent] auto-retranslate disabled');

            return;
        }

        $supported = array_keys((array) config('lingua.supported_languages', []));
        $source = (string) config('lingua.source_language', 'en');
        $targets = array_values(array_diff($supported, [$source]));

        foreach ($targets as $lang) {
            $tracker->initializeForLanguage($lang, count($changed));

            WarmAllPagesJob::dispatch([$lang], $changed, true)
                ->onQueue(config('lingua.queue_name', 'lingua'));
        }

        $tracker->pushNotification([
            'type' => 'change_detected',
            'pages' => count($changed),
            'languages' => $targets,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

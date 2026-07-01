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
 * Scheduled every 6h. Discovers public GET routes, decides whether anything
 * has changed since the last run, and — when warm-on-discovery is enabled —
 * dispatches a WarmAllPagesJob per target language with progress tracking
 * already initialized.
 */
class LinguaAgentDiscoveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(HttpKernel $kernel, LinguaAgent $agent, ProgressTracker $tracker): void
    {
        if (! config('lingua.agent.enabled', false)) {
            Log::channel('single')->info('[LinguaLayer][agent] discovery skipped — agent disabled');

            return;
        }

        if (! $agent->needsFullScan()) {
            Log::channel('single')->info('[LinguaLayer][agent] discovery skipped — not due yet');

            return;
        }

        $newPages = $agent->scanForNewPages($kernel);

        Log::channel('single')->info('[LinguaLayer][agent] discovery complete', [
            'new_or_changed_pages' => count($newPages),
        ]);

        if (empty($newPages)) {
            return;
        }

        if (! config('lingua.agent.auto_warm_new_pages', true)) {
            Log::channel('single')->info('[LinguaLayer][agent] auto-warm disabled — skipping warm dispatch');

            return;
        }

        $supported = array_keys((array) config('lingua.supported_languages', []));
        $source = (string) config('lingua.source_language', 'en');
        $targets = array_values(array_diff($supported, [$source]));

        // Initialize progress for every target language so the dashboard shows
        // bars from the moment the warm jobs hit the queue, even before any
        // page completes.
        foreach ($targets as $lang) {
            $tracker->initializeForLanguage($lang, count($newPages));

            WarmAllPagesJob::dispatch([$lang], $newPages, false)
                ->onQueue(config('lingua.queue_name', 'lingua'));
        }

        $tracker->pushNotification([
            'type' => 'discovery',
            'pages' => count($newPages),
            'languages' => $targets,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

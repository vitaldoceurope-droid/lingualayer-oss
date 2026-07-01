<?php

namespace LinguaLayer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentState;
use LinguaLayer\Models\Translation;

/**
 * Scheduled nightly at 02:00. Marks every translation with score < 7 obsolete
 * so it gets re-translated on the next visit. Logs a summary for the
 * dashboard.
 */
class LinguaAgentQualityCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        if (! config('lingua.agent.enabled', false)) {
            return;
        }

        try {
            if (! Schema::hasTable('lingua_translations')) {
                Log::channel('single')->info('[LinguaLayer][agent] quality-check skipped — table missing');

                return;
            }
        } catch (\Throwable) {
            return;
        }

        $threshold = (int) config('lingua.agent.quality_threshold', 7);

        $affected = Translation::query()
            ->whereNotNull('score')
            ->where('score', '<', $threshold)
            ->where('is_obsolete', false)
            ->update(['is_obsolete' => true]);

        Log::channel('single')->info('[LinguaLayer][agent] quality-check complete', [
            'low_score_invalidated' => $affected,
            'threshold' => $threshold,
        ]);

        if (Schema::hasTable('lingua_agent_state')) {
            AgentState::singleton()->forceFill([
                'last_quality_check_at' => now(),
            ])->save();
        }
    }
}

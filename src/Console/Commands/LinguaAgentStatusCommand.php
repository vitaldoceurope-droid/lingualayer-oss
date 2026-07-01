<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentState;
use LinguaLayer\Services\ProgressTracker;

class LinguaAgentStatusCommand extends Command
{
    protected $signature = 'lingua:agent:status';

    protected $description = 'Show agent state, progress per language and last activity timestamps';

    public function handle(ProgressTracker $tracker): int
    {
        $this->line('');
        $this->line('<fg=magenta;options=bold>🤖 LinguaLayer Agent — Status</>');
        $this->line('─────────────────────────────────────');

        if (! Schema::hasTable('lingua_agent_state')) {
            $this->warn('Agent tables missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $state = AgentState::singleton();

        $enabled = $state->enabled && config('lingua.agent.enabled', false);
        $stateLabel = $enabled ? '<fg=green>ACTIVE</>' : '<fg=yellow>DISABLED</>';

        $this->line("  Status:                {$stateLabel}");
        $this->line('  Pages monitored:       <options=bold>'.$state->pages_known.'</>');
        $this->line('  Last full scan:        '.($state->last_full_scan_at?->diffForHumans() ?? 'never'));
        $this->line('  Last change check:     '.($state->last_change_check_at?->diffForHumans() ?? 'never'));
        $this->line('  Last quality check:    '.($state->last_quality_check_at?->diffForHumans() ?? 'never'));
        $this->line('');

        $progress = $tracker->getProgress();
        if (empty($progress)) {
            $this->line('<fg=yellow>No language progress yet — agent has not warmed any pages.</>');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($progress as $lang => $info) {
            $rows[] = [
                $lang,
                sprintf('%d / %d', $info['pages_translated'], $info['pages_total']),
                sprintf('%.1f%%', $info['percentage']),
                $info['eta_human'] ?? '—',
                $info['status'],
                $info['last_page'] ?? '—',
            ];
        }
        $this->table(
            ['Lang', 'Pages', 'Percent', 'ETA', 'Status', 'Last page'],
            $rows
        );

        $overall = $tracker->getOverallProgress();
        $this->line(sprintf(
            '  Overall: <options=bold>%d / %d</> (%.1f%%) — %d/%d languages completed',
            $overall['completed_translations'],
            $overall['total_translations'],
            $overall['percentage'],
            $overall['languages_completed'],
            $overall['languages_target'],
        ));
        if ($overall['all_completed'] ?? false) {
            $this->line('');
            $this->line('<fg=green;options=bold>🎉 All languages completed.</>');
        }
        $this->line('');

        return self::SUCCESS;
    }
}

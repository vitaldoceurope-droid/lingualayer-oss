<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use LinguaLayer\Services\ProgressTracker;

/**
 * Live progress watcher. Refreshes every 2 seconds. Exit with Ctrl+C.
 *
 * --once flag prints a single snapshot and exits, useful for scripts and tests.
 */
class LinguaAgentProgressCommand extends Command
{
    protected $signature = 'lingua:agent:progress {--once : Print a single snapshot and exit} {--interval=2 : Refresh interval seconds}';

    protected $description = 'Watch translation progress live with ASCII progress bars';

    public function handle(ProgressTracker $tracker): int
    {
        $once = (bool) $this->option('once');
        $interval = max(1, (int) $this->option('interval'));

        do {
            if (! $once) {
                $this->getOutput()->write("\033[2J\033[H");
            }

            $this->renderSnapshot($tracker);

            if ($once) {
                break;
            }

            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }

    private function renderSnapshot(ProgressTracker $tracker): void
    {
        $this->line('<fg=magenta;options=bold>🤖 LinguaLayer Agent — Live Progress</>');
        $this->line('─────────────────────────────────────────────────');
        $this->line('');

        $progress = $tracker->getProgress();
        if (empty($progress)) {
            $this->line('  <fg=yellow>No active runs.</>');
            $this->line('  Trigger one with: <options=bold>php artisan lingua:agent:scan --force</>');

            return;
        }

        foreach ($progress as $lang => $info) {
            $bar = $this->renderBar($info['percentage'] ?? 0);
            $statusIcon = match ($info['status']) {
                'completed' => '<fg=green>✓ Completed</>',
                'running' => '<fg=cyan>🔄 Translating</>',
                'error' => '<fg=red>✗ Error</>',
                default => '<fg=yellow>idle</>',
            };
            $this->line(sprintf(
                '  <options=bold>%-3s</>  %s  <options=bold>%5.1f%%</>  %s',
                $lang,
                $bar,
                $info['percentage'] ?? 0,
                $statusIcon
            ));
            $this->line(sprintf(
                '       %d/%d pages · ETA %s · last: %s',
                $info['pages_translated'] ?? 0,
                $info['pages_total'] ?? 0,
                $info['eta_human'] ?? '—',
                $info['last_page'] ?? '—',
            ));
            $this->line('');
        }

        $overall = $tracker->getOverallProgress();
        $this->line('─────────────────────────────────────────────────');
        $this->line(sprintf(
            '  Total: <options=bold>%d/%d</> (%.1f%%) · %d/%d languages completed',
            $overall['completed_translations'],
            $overall['total_translations'],
            $overall['percentage'],
            $overall['languages_completed'],
            $overall['languages_target'],
        ));
        if ($overall['all_completed'] ?? false) {
            $this->line('  <fg=green;options=bold>🎉 ALL LANGUAGES COMPLETE</>');
        }

        if (! $this->option('once')) {
            $this->line('');
            $this->line('  <fg=gray>Press Ctrl+C to exit</>');
        }
    }

    private function renderBar(float $pct): string
    {
        $width = 20;
        $filled = (int) round(($pct / 100) * $width);
        $empty = $width - $filled;

        return '<fg=cyan>'.str_repeat('█', $filled).'</><fg=gray>'.str_repeat('░', $empty).'</>';
    }
}

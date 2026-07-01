<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use LinguaLayer\Jobs\WarmAllPagesJob;

class LinguaInstallCommand extends Command
{
    protected $signature = 'lingua:install
        {--no-warm : Skip the post-install warm prompt entirely}';

    protected $description = 'Publish LinguaLayer config + assets + migrations and optionally pre-translate all public pages';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold> LinguaLayer Install </>');
        $this->line('─────────────────────────────────────');

        // 1. Config
        $this->line('Publishing config…');
        $this->callSilent('vendor:publish', ['--tag' => 'lingua-config', '--force' => false]);
        $this->line('  <fg=green>✓</> config/lingua.php');

        // 2. Assets (force so updates land on subsequent installs)
        $this->line('Publishing JS asset…');
        $this->callSilent('vendor:publish', ['--tag' => 'lingua-assets', '--force' => true]);
        $this->line('  <fg=green>✓</> public/vendor/lingualayer/lingua.js');

        // 3. Migrations (only writes if user opts in to vendor:publish)
        $this->line('Publishing migrations…');
        $this->callSilent('vendor:publish', ['--tag' => 'lingua-migrations', '--force' => false]);
        $this->line('  <fg=green>✓</> database/migrations/*_create_lingua_training_samples_table.php');
        $this->line('    (run <options=bold>php artisan migrate</> to apply — only required if you enable few-shot learning)');

        // 4. Warm prompt — only when async queue is wired up
        $this->line('');
        $this->maybePromptWarm();

        $this->line('');
        $this->line('<fg=green;options=bold>✓ LinguaLayer installed.</>');
        $this->line('  Next: set <options=bold>LINGUA_GEMINI_KEY</> in .env and run <options=bold>php artisan lingua:test</>.');
        $this->line('');
        $this->line('  <fg=cyan>For automatic pre-warming (no queue worker needed),</> add ONE cron line:');
        $this->line('    <options=bold>* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1</>');
        $this->line('  (Standard Laravel scheduler — translation still works without it, just not pre-warmed.)');
        $this->line('');

        return self::SUCCESS;
    }

    private function maybePromptWarm(): void
    {
        if ($this->option('no-warm')) {
            return;
        }

        $queueDriver = config('queue.default', 'sync');

        if ($queueDriver === 'sync') {
            $this->line('<fg=yellow>⚠</> Queue driver is <options=bold>sync</>. Running warm now would block this command.');
            $this->line('  Configure a real queue driver (database/redis) and run:');
            $this->line('    <options=bold>php artisan lingua:warm</>');

            return;
        }

        if (! $this->input->isInteractive()) {
            $this->line('Non-interactive mode — skipping warm. Run <options=bold>php artisan lingua:warm</> when ready.');

            return;
        }

        if (! $this->confirm('Pre-translate all public pages now in background?', true)) {
            $this->line('Skipped. Run <options=bold>php artisan lingua:warm</> later.');

            return;
        }

        WarmAllPagesJob::dispatch()->onQueue(config('lingua.queue_name', 'lingua'));
        $this->line('  <fg=green>✓</> WarmAllPagesJob dispatched to queue <options=bold>'.config('lingua.queue_name', 'lingua').'</>');
        $this->line('    Make sure a worker is running: <options=bold>php artisan queue:work --queue='.config('lingua.queue_name', 'lingua').'</>');
    }
}

<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentState;

class LinguaAgentEnableCommand extends Command
{
    protected $signature = 'lingua:agent:enable';

    protected $description = 'Enable the autonomous LinguaLayer agent (BD-level toggle)';

    public function handle(): int
    {
        if (! Schema::hasTable('lingua_agent_state')) {
            $this->error('Agent table missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        AgentState::singleton()->forceFill(['enabled' => true])->save();
        $this->info('Agent enabled.');
        $this->line('Note: also ensure LINGUA_AGENT_ENABLED is true in .env');

        return self::SUCCESS;
    }
}

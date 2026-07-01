<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentState;

class LinguaAgentDisableCommand extends Command
{
    protected $signature = 'lingua:agent:disable';

    protected $description = 'Disable the autonomous LinguaLayer agent (BD-level toggle)';

    public function handle(): int
    {
        if (! Schema::hasTable('lingua_agent_state')) {
            $this->error('Agent table missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        AgentState::singleton()->forceFill(['enabled' => false])->save();
        $this->info('Agent disabled. Schedule jobs will skip until re-enabled.');

        return self::SUCCESS;
    }
}

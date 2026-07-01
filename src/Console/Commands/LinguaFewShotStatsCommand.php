<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LinguaFewShotStatsCommand extends Command
{
    protected $signature = 'lingua:fewshot-stats';

    protected $description = 'Show few-shot training sample statistics per language pair';

    public function handle(): int
    {
        try {
            if (! Schema::hasTable('lingua_training_samples')) {
                $this->warn('Table lingua_training_samples does not exist.');
                $this->line('Run: php artisan migrate');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Database connection error: '.$e->getMessage());
            $this->line('Make sure your database is configured and reachable.');

            return self::FAILURE;
        }

        $rows = DB::table('lingua_training_samples')
            ->selectRaw(
                'source_lang, target_lang, COUNT(*) as count, '
                .'AVG(score) as avg_score, '
                .'SUM(CASE WHEN validated = 1 THEN 1 ELSE 0 END) as validated_count'
            )
            ->groupBy('source_lang', 'target_lang')
            ->orderByDesc('count')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No training samples yet.');
            $this->line('Enable LINGUA_AUTO_SCORE=true and LINGUA_FEW_SHOT_ENABLED=true, then wait for background translations to be scored.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pair', 'Samples', 'Avg Score', 'Validated', 'Status'],
            $rows->map(fn ($r) => [
                "{$r->source_lang} → {$r->target_lang}",
                $r->count,
                number_format($r->avg_score, 1),
                $r->validated_count,
                match (true) {
                    $r->count >= 5 => '<fg=green>ACTIVO</>',
                    $r->count >= 2 => '<fg=yellow>POCOS</>',
                    default => '<fg=red>PROMPT BASE</>',
                },
            ])
        );

        $total = $rows->sum('count');
        $this->newLine();
        $this->line("Total samples: {$total}");
        $this->line('ACTIVO = few-shot active (5+ samples) | POCOS = building up | PROMPT BASE = using base prompt');

        return self::SUCCESS;
    }
}

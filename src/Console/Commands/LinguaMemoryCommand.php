<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\TranslationStore;

/**
 * Inspect, export and import the translation memory — the growing library of
 * already-translated fragments that lets the package serve repeat content with
 * zero LLM calls.
 *
 *   php artisan lingua:memory                  # stats
 *   php artisan lingua:memory export tm.jsonl  # back up / move the memory
 *   php artisan lingua:memory import tm.jsonl  # seed a fresh install
 *
 * The export is portable JSONL: one self-contained translation per line. Seed a
 * brand-new client install with an existing corpus and it starts with instant
 * coverage instead of paying the LLM to re-learn everything.
 */
class LinguaMemoryCommand extends Command
{
    protected $signature = 'lingua:memory
        {action=stats : stats | export | import}
        {file? : JSONL file path (required for export/import)}
        {--include-obsolete : include obsolete rows when exporting}';

    protected $description = 'Inspect, export or import the translation memory (lingua_translations)';

    public function handle(TranslationStore $store): int
    {
        try {
            if (! Schema::hasTable('lingua_translations')) {
                $this->warn('Table lingua_translations does not exist.');
                $this->line('Run: php artisan migrate');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Database connection error: '.$e->getMessage());

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'stats' => $this->showStats($store),
            'export' => $this->export(),
            'import' => $this->import($store),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use one of: stats, export, import.');

        return self::FAILURE;
    }

    private function showStats(TranslationStore $store): int
    {
        $stats = $store->stats();

        $this->info('Translation memory');
        $this->line("  Active entries:   {$stats['total_active']}");
        $this->line("  Obsolete entries: {$stats['total_obsolete']}");
        $this->line('  Avg quality score: '.($stats['avg_score'] ?? 'n/a'));

        if (! empty($stats['by_language'])) {
            $this->newLine();
            $this->table(
                ['Target language', 'Entries'],
                collect($stats['by_language'])
                    ->map(fn ($count, $lang) => [$lang, $count])
                    ->values()
                    ->all()
            );
        }

        $this->newLine();
        $this->line('Export it with:  php artisan lingua:memory export memory.jsonl');

        return self::SUCCESS;
    }

    private function export(): int
    {
        $file = (string) $this->argument('file');
        if ($file === '') {
            $this->error('Provide an output path: php artisan lingua:memory export memory.jsonl');

            return self::FAILURE;
        }

        $handle = @fopen($file, 'w');
        if ($handle === false) {
            $this->error("Cannot open {$file} for writing.");

            return self::FAILURE;
        }

        $query = Translation::query()->orderBy('id');
        if (! $this->option('include-obsolete')) {
            $query->where('is_obsolete', false);
        }

        $count = 0;
        $query->chunk(1000, function ($rows) use ($handle, &$count) {
            foreach ($rows as $r) {
                $line = json_encode([
                    'source' => $r->source_text,
                    'source_lang' => $r->source_lang,
                    'target_lang' => $r->target_lang,
                    'translated' => $r->translated_text,
                    'score' => $r->score,
                    'model_used' => $r->model_used,
                    'page_url' => $r->page_url,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($line !== false) {
                    fwrite($handle, $line."\n");
                    $count++;
                }
            }
        });

        fclose($handle);

        $this->info("Exported {$count} translation(s) to {$file}");

        return self::SUCCESS;
    }

    private function import(TranslationStore $store): int
    {
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            $this->error("Cannot open {$file} for reading.");

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $buffer = [];

        while (($raw = fgets($handle)) !== false) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $row = json_decode($raw, true);
            if (! is_array($row) || ! isset($row['source'], $row['target_lang'], $row['translated'])) {
                $skipped++;

                continue;
            }

            $buffer[] = [
                'source' => (string) $row['source'],
                'source_lang' => (string) ($row['source_lang'] ?? config('lingua.source_language', 'en')),
                'target_lang' => (string) $row['target_lang'],
                'translated' => (string) $row['translated'],
                'model_used' => $row['model_used'] ?? 'imported',
                'score' => $row['score'] ?? null,
                'page_url' => $row['page_url'] ?? null,
            ];

            if (count($buffer) >= 500) {
                $store->batchStore($buffer);
                $imported += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $store->batchStore($buffer);
            $imported += count($buffer);
        }

        fclose($handle);

        $this->info("Imported {$imported} translation(s)".($skipped > 0 ? ", skipped {$skipped} malformed line(s)." : '.'));

        return self::SUCCESS;
    }
}

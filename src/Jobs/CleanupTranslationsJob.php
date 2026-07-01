<?php

namespace LinguaLayer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\TranslationStore;

/**
 * Nightly maintenance for the persistent translation store.
 *
 *   1. Mark translations not seen in N days as obsolete.
 *   2. Optionally archive long-obsolete rows to JSONL on disk before deletion.
 *   3. Permanently delete obsolete rows older than M days.
 *
 * Defaults err on the side of preservation: 30-day obsolete window, 90-day
 * delete window, archive enabled. Operators have ample time to spot deletes
 * before they hit.
 */
class CleanupTranslationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(TranslationStore $store): void
    {
        if (! $store->isAvailable()) {
            Log::channel('single')->info('[LinguaLayer] Cleanup skipped: store unavailable');

            return;
        }

        $obsoleteDays = (int) config('lingua.translations_obsolete_days', 30);
        $deleteDays = (int) config('lingua.translations_delete_days', 90);

        // Step 1: mark stale translations
        $marked = $store->markObsolete($obsoleteDays);

        // Step 2: archive (optional) the rows that are about to be deleted
        $archived = 0;
        if (config('lingua.translations_archive', true)) {
            $archived = $this->archiveObsolete($deleteDays);
        }

        // Step 3: delete obsolete rows older than the delete window
        $deleted = $store->cleanup($deleteDays);

        Log::channel('single')->info('[LinguaLayer] Cleanup completed', [
            'marked_obsolete' => $marked,
            'archived' => $archived,
            'deleted' => $deleted,
            'obsolete_days' => $obsoleteDays,
            'delete_days' => $deleteDays,
        ]);
    }

    /**
     * Stream obsolete rows older than the delete window into a daily JSONL
     * file under storage/app/lingua/archive/{Y-m-d}.jsonl. Chunked at 500 to
     * keep memory bounded on large stores.
     */
    private function archiveObsolete(int $deleteDays): int
    {
        try {
            $cutoff = now()->subDays($deleteDays);
            $path = 'lingua/archive/'.now()->format('Y-m-d').'.jsonl';

            $count = 0;
            $payload = '';

            Translation::query()
                ->where('is_obsolete', true)
                ->where('updated_at', '<', $cutoff)
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$payload, &$count) {
                    foreach ($rows as $r) {
                        $payload .= json_encode([
                            'id' => $r->id,
                            'source_hash' => $r->source_hash,
                            'source_text' => $r->source_text,
                            'source_lang' => $r->source_lang,
                            'target_lang' => $r->target_lang,
                            'translated_text' => $r->translated_text,
                            'model_used' => $r->model_used,
                            'score' => $r->score,
                            'page_url' => $r->page_url,
                            'times_used' => $r->times_used,
                            'created_at' => optional($r->created_at)->toIso8601String(),
                            'last_seen_at' => optional($r->last_seen_at)->toIso8601String(),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                        $count++;
                    }
                });

            if ($payload !== '') {
                Storage::disk('local')->append($path, rtrim($payload, "\n"));
            }

            return $count;
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer] Archive failed', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}

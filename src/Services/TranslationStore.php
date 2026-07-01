<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\Translation;

/**
 * Persistent, queryable storage for translated fragments.
 *
 * The cache layer (TranslationCache) remains as a fast-path / fallback. This
 * store is the source of truth: every translation eventually lands here so
 * we can audit it, detect drift, and rebuild the cache from a clean slate.
 */
class TranslationStore
{
    /**
     * True if the underlying connection has the lingua_translations table.
     * Cached per-instance because Schema::hasTable issues an information_schema
     * query — we do not want to repeat it on every batch call.
     */
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $this->available = Schema::hasTable('lingua_translations');
        } catch (\Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    public function find(string $sourceText, string $sourceLang, string $targetLang): ?Translation
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $hash = Translation::makeHash($sourceText, $sourceLang);

        try {
            $row = Translation::where('source_hash', $hash)
                ->where('target_lang', $targetLang)
                ->first();
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] TranslationStore::find DB error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($row !== null) {
            $row->markAsSeen();
        }

        return $row;
    }

    /**
     * Look up many texts in one SQL query.
     *
     * @param  array<int,string>  $texts
     * @return array<string,Translation> keyed by source_hash so the caller can
     *                                   map back to the original input order.
     */
    public function batchFind(array $texts, string $sourceLang, string $targetLang): array
    {
        if (! $this->isAvailable() || empty($texts)) {
            return [];
        }

        $hashes = [];
        foreach ($texts as $t) {
            $hashes[Translation::makeHash($t, $sourceLang)] = true;
        }
        $hashes = array_keys($hashes);

        try {
            // Single SELECT — N rows in, ≤ N rows out.
            $rows = Translation::whereIn('source_hash', $hashes)
                ->where('target_lang', $targetLang)
                ->get();
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] TranslationStore::batchFind DB error', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($rows->isEmpty()) {
            return [];
        }

        // Bulk update last_seen_at + times_used in one SQL statement instead
        // of looping through the models. Avoids N writes on every page load.
        $now = now();
        try {
            Translation::whereIn('id', $rows->pluck('id'))->update([
                'last_seen_at' => $now,
                'times_used' => DB::raw('times_used + 1'),
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] TranslationStore bulk markAsSeen error', [
                'error' => $e->getMessage(),
            ]);
        }

        $byHash = [];
        foreach ($rows as $r) {
            $byHash[$r->source_hash] = $r;
        }

        return $byHash;
    }

    /**
     * Insert or update a single translation. Resets is_obsolete because we just
     * verified Gemini is producing this same translation again (or for the
     * first time) — it's by definition fresh.
     *
     * @param  array<string,mixed>  $metadata  page_url, element_path, score, model_used
     */
    public function store(
        string $sourceText,
        string $sourceLang,
        string $targetLang,
        string $translatedText,
        array $metadata = []
    ): ?Translation {
        if (! $this->isAvailable()) {
            return null;
        }

        $hash = Translation::makeHash($sourceText, $sourceLang);
        $now = now();

        $values = array_merge([
            'source_text' => $sourceText,
            'source_lang' => $sourceLang,
            'translated_text' => $translatedText,
            'is_obsolete' => false,
            'last_seen_at' => $now,
        ], array_intersect_key($metadata, array_flip([
            'model_used', 'score', 'page_url', 'element_path',
        ])));

        try {
            return Translation::updateOrCreate(
                ['source_hash' => $hash, 'target_lang' => $targetLang],
                $values
            );
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer] TranslationStore::store error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Bulk upsert. Each $items entry must contain at least: source, target_lang,
     * translated. Optional: source_lang (defaults to config), model_used, score,
     * page_url, element_path.
     *
     * Uses Eloquent upsert() which compiles to a single multi-row
     * INSERT … ON CONFLICT … DO UPDATE on PostgreSQL/SQLite or
     * INSERT … ON DUPLICATE KEY UPDATE on MySQL.
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    public function batchStore(array $items): void
    {
        if (! $this->isAvailable() || empty($items)) {
            return;
        }

        $defaultSource = config('lingua.source_language', 'en');
        $now = now();
        $rows = [];

        foreach ($items as $it) {
            if (! isset($it['source'], $it['target_lang'], $it['translated'])) {
                continue;
            }
            $sourceLang = $it['source_lang'] ?? $defaultSource;
            $rows[] = [
                'source_hash' => Translation::makeHash($it['source'], $sourceLang),
                'source_text' => $it['source'],
                'source_lang' => $sourceLang,
                'target_lang' => $it['target_lang'],
                'translated_text' => $it['translated'],
                'model_used' => $it['model_used'] ?? null,
                'score' => $it['score'] ?? null,
                'page_url' => $it['page_url'] ?? null,
                'element_path' => $it['element_path'] ?? null,
                'is_obsolete' => false,
                'times_used' => 0,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        try {
            // Upsert by the unique composite (source_hash, target_lang). On
            // conflict we refresh translated_text + metadata + is_obsolete=false
            // but preserve times_used and created_at.
            Translation::upsert(
                $rows,
                ['source_hash', 'target_lang'],
                ['source_text', 'translated_text', 'model_used', 'score',
                    'page_url', 'element_path', 'is_obsolete', 'last_seen_at', 'updated_at']
            );
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer] TranslationStore::batchStore error', [
                'error' => $e->getMessage(),
                'count' => count($rows),
            ]);
        }
    }

    /**
     * Mark every row not seen in the last N days as obsolete.
     *
     * @return int rows affected
     */
    public function markObsolete(int $olderThanDays = 30): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        try {
            return Translation::query()
                ->where('is_obsolete', false)
                ->where(function ($q) use ($olderThanDays) {
                    $q->where('last_seen_at', '<', now()->subDays($olderThanDays))
                        ->orWhere(function ($q2) use ($olderThanDays) {
                            $q2->whereNull('last_seen_at')
                                ->where('created_at', '<', now()->subDays($olderThanDays));
                        });
                })
                ->update(['is_obsolete' => true, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer] markObsolete error', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Permanently delete obsolete rows whose updated_at is older than N days.
     * The two-step cycle (mark, then delete N days later) gives operators
     * time to inspect or restore before data is gone.
     *
     * @return int rows deleted
     */
    public function cleanup(int $obsoleteOlderThanDays = 90): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        try {
            return Translation::query()
                ->where('is_obsolete', true)
                ->where('updated_at', '<', now()->subDays($obsoleteOlderThanDays))
                ->delete();
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer] cleanup error', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Best-effort change detection. Looks for previous translations on the
     * same page_url whose source_text is "very similar" to the new one (>=
     * threshold by similar_text percent). The likely match is returned so
     * the caller can mark it obsolete.
     *
     * Returns null when no candidates, no page_url, or store unavailable.
     */
    public function detectChanges(string $newText, string $pageUrl, string $targetLang, float $threshold = 0.8): ?Translation
    {
        if (! $this->isAvailable() || $pageUrl === '') {
            return null;
        }

        try {
            // Cap the candidate set — a page with hundreds of strings is fine
            // because we only care about non-obsolete ones with text length in
            // a reasonable distance of $newText.
            $candidates = Translation::query()
                ->where('page_url', $pageUrl)
                ->where('target_lang', $targetLang)
                ->where('is_obsolete', false)
                ->limit(100)
                ->get();
        } catch (\Throwable) {
            return null;
        }

        $newLen = max(mb_strlen($newText), 1);

        foreach ($candidates as $c) {
            $oldLen = max(mb_strlen($c->source_text), 1);
            // Skip obvious mismatches by length to avoid expensive similar_text.
            if (abs($oldLen - $newLen) / max($oldLen, $newLen) > (1 - $threshold)) {
                continue;
            }
            // Already identical → not a change, just a re-translate.
            if ($c->source_text === $newText) {
                return null;
            }
            similar_text($c->source_text, $newText, $percent);
            if ($percent / 100 >= $threshold) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  string|null  $targetLang  optional filter
     * @return array{
     *   total_active:int, total_obsolete:int, avg_score:float|null,
     *   top_used: array<int,Translation>, by_language: array<string,int>
     * }
     */
    public function stats(?string $targetLang = null): array
    {
        $empty = [
            'total_active' => 0,
            'total_obsolete' => 0,
            'avg_score' => null,
            'top_used' => [],
            'by_language' => [],
        ];

        if (! $this->isAvailable()) {
            return $empty;
        }

        try {
            $base = Translation::query();
            if ($targetLang !== null) {
                $base->where('target_lang', $targetLang);
            }

            $active = (clone $base)->where('is_obsolete', false)->count();
            $obsolete = (clone $base)->where('is_obsolete', true)->count();
            $avg = (clone $base)->whereNotNull('score')->avg('score');
            $top = (clone $base)->where('is_obsolete', false)
                ->orderByDesc('times_used')->limit(10)->get();

            $byLang = $targetLang
                ? []
                : Translation::query()
                    ->selectRaw('target_lang, COUNT(*) as c')
                    ->groupBy('target_lang')
                    ->pluck('c', 'target_lang')
                    ->toArray();

            return [
                'total_active' => $active,
                'total_obsolete' => $obsolete,
                'avg_score' => $avg !== null ? round((float) $avg, 1) : null,
                'top_used' => $top->all(),
                'by_language' => $byLang,
            ];
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer] stats error', [
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }
}

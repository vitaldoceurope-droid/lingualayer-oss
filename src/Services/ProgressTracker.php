<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentProgress;

/**
 * Per-language progress tracker. Owns the lingua_agent_progress rows and
 * the in-memory ETA computation. The dashboard reads getProgress() /
 * getOverallProgress() once every few seconds.
 *
 * Defensive: every public method returns an empty/default value when the
 * underlying table is missing instead of throwing.
 */
class ProgressTracker
{
    /** Notification ring-buffer key in the cache layer. Survives restarts. */
    public const NOTIFICATIONS_CACHE_KEY = 'lingua_agent_notifications';

    private const NOTIFICATIONS_MAX = 20;

    /**
     * Reset (or create) the progress row for a language at the start of a run.
     */
    public function initializeForLanguage(string $targetLang, int $totalPages): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            AgentProgress::updateOrCreate(
                ['target_lang' => $targetLang],
                [
                    'pages_total' => $totalPages,
                    'pages_translated' => 0,
                    'pages_pending' => $totalPages,
                    'pages_failed' => 0,
                    'fragments_total' => 0,
                    'fragments_translated' => 0,
                    'started_at' => now(),
                    'completed_at' => null,
                    'estimated_seconds_remaining' => null,
                    'last_page_completed' => null,
                    'status' => $totalPages > 0 ? 'running' : 'idle',
                ]
            );

            $this->pushNotification([
                'type' => 'started',
                'language' => $targetLang,
                'pages' => $totalPages,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer][progress] init failed', [
                'lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Increment counters when a single page finishes successfully. Recomputes
     * the ETA based on a rolling average of seconds-per-page so far.
     */
    public function recordPageCompleted(string $targetLang, string $url, int $fragmentsCount = 0): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $row = AgentProgress::where('target_lang', $targetLang)->first();
            if (! $row) {
                return;
            }

            $row->pages_translated = min($row->pages_translated + 1, $row->pages_total);
            $row->pages_pending = max($row->pages_total - $row->pages_translated - $row->pages_failed, 0);
            $row->fragments_translated += max(0, $fragmentsCount);
            $row->fragments_total = max($row->fragments_total, $row->fragments_translated);
            $row->last_page_completed = $url;

            $row->estimated_seconds_remaining = $this->estimateRemaining($row);

            if ($row->pages_translated + $row->pages_failed >= $row->pages_total) {
                $row->status = $row->pages_failed === 0 ? 'completed' : 'error';
                $row->completed_at = now();
            }

            $row->save();

            if ($row->status === 'completed') {
                $this->pushNotification([
                    'type' => 'completed',
                    'language' => $targetLang,
                    'pages' => $row->pages_total,
                    'fragments' => $row->fragments_translated,
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer][progress] record-completed failed', [
                'lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordPageFailed(string $targetLang, string $url): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $row = AgentProgress::where('target_lang', $targetLang)->first();
            if (! $row) {
                return;
            }

            $row->pages_failed++;
            $row->pages_pending = max($row->pages_total - $row->pages_translated - $row->pages_failed, 0);

            if ($row->pages_translated + $row->pages_failed >= $row->pages_total) {
                $row->status = 'error';
                $row->completed_at = now();
            }

            $row->save();

            Log::channel('single')->warning('[LinguaLayer][progress] page failed', [
                'lang' => $targetLang,
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer][progress] record-failed error', [
                'lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Snapshot for one language or all. Shape matches the JSON endpoint contract.
     *
     * @return array<string,mixed>|array<string,array<string,mixed>>
     */
    public function getProgress(?string $targetLang = null): array
    {
        if (! $this->available()) {
            return $targetLang ? [] : [];
        }

        try {
            $query = AgentProgress::query();
            if ($targetLang !== null) {
                $row = $query->where('target_lang', $targetLang)->first();

                return $row ? $this->shape($row) : [];
            }

            $out = [];
            foreach ($query->get() as $row) {
                $out[$row->target_lang] = $this->shape($row);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer][progress] getProgress error', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Aggregate across all configured target languages.
     *
     * @return array<string,mixed>
     */
    public function getOverallProgress(): array
    {
        $supported = array_keys((array) config('lingua.supported_languages', []));
        $source = (string) config('lingua.source_language', 'en');
        $targets = array_values(array_diff($supported, [$source]));

        $total = 0;
        $completed = 0;
        $active = 0;
        $done = 0;

        if ($this->available()) {
            try {
                foreach (AgentProgress::all() as $row) {
                    if (! in_array($row->target_lang, $targets, true)) {
                        continue;
                    }
                    $total += $row->pages_total;
                    $completed += $row->pages_translated;
                    if ($row->status === 'running') {
                        $active++;
                    }
                    if ($row->status === 'completed') {
                        $done++;
                    }
                }
            } catch (\Throwable) {
                // fallthrough — empty aggregate
            }
        }

        $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;

        return [
            'total_translations' => $total,
            'completed_translations' => $completed,
            'percentage' => $pct,
            'languages_active' => $active,
            'languages_completed' => $done,
            'languages_target' => count($targets),
            'all_completed' => $total > 0 && $completed >= $total,
        ];
    }

    /** Append a notification to the rolling buffer (newest first). */
    public function pushNotification(array $payload): void
    {
        try {
            $driver = config('lingua.cache_driver', 'file');
            $cache = Cache::driver($driver);
            $list = (array) ($cache->get(self::NOTIFICATIONS_CACHE_KEY) ?? []);
            array_unshift($list, $payload);
            $list = array_slice($list, 0, self::NOTIFICATIONS_MAX);
            $cache->put(self::NOTIFICATIONS_CACHE_KEY, $list, 86400 * 7);
        } catch (\Throwable) {
            // notifications are best-effort
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getNotifications(int $limit = 10): array
    {
        try {
            $driver = config('lingua.cache_driver', 'file');
            $list = (array) (Cache::driver($driver)->get(self::NOTIFICATIONS_CACHE_KEY) ?? []);

            return array_slice($list, 0, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Compute remaining seconds using a rolling average of seconds-per-page.
     * Returns null until at least one page has completed.
     */
    private function estimateRemaining(AgentProgress $row): ?int
    {
        if ($row->pages_translated <= 0 || $row->started_at === null) {
            return null;
        }

        $elapsed = max(now()->getTimestamp() - $row->started_at->getTimestamp(), 1);
        $perPage = $elapsed / $row->pages_translated;

        $remainingPages = max($row->pages_total - $row->pages_translated - $row->pages_failed, 0);
        if ($remainingPages === 0) {
            return 0;
        }

        return (int) round($perPage * $remainingPages);
    }

    /**
     * @return array<string,mixed>
     */
    private function shape(AgentProgress $row): array
    {
        $pct = $row->percentage();
        $eta = $row->estimated_seconds_remaining;
        $human = $eta === null
            ? null
            : $this->humanizeSeconds($eta);

        return [
            'pages_total' => $row->pages_total,
            'pages_translated' => $row->pages_translated,
            'pages_pending' => $row->pages_pending,
            'pages_failed' => $row->pages_failed,
            'fragments_total' => $row->fragments_total,
            'fragments_translated' => $row->fragments_translated,
            'percentage' => $pct,
            'estimated_seconds_remaining' => $eta,
            'estimated_human' => $human,
            'eta_human' => $human, // alias for the dashboard JS
            'status' => $row->status,
            'started_at' => $row->started_at?->toIso8601String(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'last_page' => $row->last_page_completed,
        ];
    }

    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds <= 1) {
            return 'segundos';
        }
        if ($seconds < 60) {
            return $seconds.' segundos';
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes.' minuto'.($minutes === 1 ? '' : 's');
        }
        $hours = (int) floor($minutes / 60);
        $rest = $minutes % 60;

        return $hours.'h'.($rest > 0 ? " {$rest}min" : '');
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lingua_agent_progress');
        } catch (\Throwable) {
            return false;
        }
    }
}

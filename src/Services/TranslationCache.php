<?php

namespace LinguaLayer\Services;

use Illuminate\Support\Facades\Cache;

class TranslationCache
{
    public const STATS_FRAGMENTS_TOTAL = 'lingua_stats:fragments_total';

    public const STATS_PAGES_TOTAL = 'lingua_stats:pages_total';

    public const STATS_HITS_TOTAL = 'lingua_stats:hits_total';

    public const STATS_CALLS_TOTAL = 'lingua_stats:gemini_calls_total';

    public const STATS_LAST_WARM = 'lingua_last_warm';

    private string $driver;

    private int $ttl;

    public function __construct()
    {
        $this->driver = config('lingua.cache_driver', 'file');
        $this->ttl = (int) config('lingua.cache_ttl', 86400);
    }

    public function get(string $text, string $lang): ?string
    {
        return Cache::driver($this->driver)->get($this->key($text, $lang));
    }

    public function set(string $text, string $lang, string $translation): void
    {
        $key = $this->key($text, $lang);
        $cache = Cache::driver($this->driver);

        // add() returns true only when the key did not already exist — we use
        // that signal to count unique fragments without storing a separate index.
        if ($cache->add($key, $translation, $this->ttl * 60)) {
            self::bumpStat(self::STATS_FRAGMENTS_TOTAL);
        } else {
            $cache->put($key, $translation, $this->ttl * 60);
        }
    }

    public function has(string $text, string $lang): bool
    {
        return Cache::driver($this->driver)->has($this->key($text, $lang));
    }

    public function forget(string $text, string $lang): void
    {
        Cache::driver($this->driver)->forget($this->key($text, $lang));
    }

    private function key(string $text, string $lang): string
    {
        // Separate with a byte that cannot appear in ISO-639-1 codes so
        // "ab"+"cen" and "abc"+"en" never collide on the same cache key.
        return 'lingua_'.md5($text.'|'.$lang);
    }

    /**
     * Best-effort counter increment. Never throws — stats must not break hot paths.
     */
    public static function bumpStat(string $key, int $by = 1): void
    {
        try {
            $cache = Cache::driver(config('lingua.cache_driver', 'file'));
            if (! $cache->add($key, $by, 86400 * 60 * 60)) {
                $cache->increment($key, $by);
            }
        } catch (\Throwable) {
            // Stats are best-effort; never break the hot path
        }
    }

    public static function readStat(string $key): int
    {
        try {
            return (int) Cache::driver(config('lingua.cache_driver', 'file'))->get($key, 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}

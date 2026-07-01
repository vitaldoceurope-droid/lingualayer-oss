<?php

namespace LinguaLayer\Jobs;

use DOMDocument;
use DOMXPath;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslationScorer;

class TranslatePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly string $html,
        private readonly string $targetLang,
        private readonly string $pageKey,
    ) {}

    public function handle(HtmlTranslator $translator, TranslationScorer $scorer): void
    {
        $driver = config('lingua.cache_driver', 'file');
        $statusKey = $this->pageKey.'_status';

        Cache::driver($driver)->put($statusKey, 'processing', 3600);

        try {
            $translated = $translator->translate($this->html, $this->targetLang);

            if ($translated !== null) {
                $ttl = config('lingua.cache_ttl', 86400) * 60;
                if (Cache::driver($driver)->add($this->pageKey, $translated, $ttl)) {
                    TranslationCache::bumpStat(TranslationCache::STATS_PAGES_TOTAL);
                } else {
                    Cache::driver($driver)->put($this->pageKey, $translated, $ttl);
                }
                Cache::driver($driver)->put($statusKey, 'ready', 3600);

                $this->runScoring($scorer);
            } else {
                Cache::driver($driver)->put($statusKey, 'failed', 600);
                Log::channel('single')->error('[LinguaLayer] Async page translation failed (atomic rollback)', [
                    'lang' => $this->targetLang,
                    'key' => $this->pageKey,
                ]);
            }
        } catch (\Throwable $e) {
            Cache::driver($driver)->put($statusKey, 'failed', 600);
            throw $e;
        }
    }

    private function runScoring(TranslationScorer $scorer): void
    {
        if (! config('lingua.auto_score', false)) {
            return;
        }

        try {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8">'.$this->html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $nodes = (new DOMXPath($dom))->query('//p | //h1 | //h2 | //h3 | //h4');
            $texts = [];
            foreach ($nodes as $node) {
                $t = trim($node->textContent);
                if (mb_strlen($t) >= 20) {
                    $texts[] = $t;
                }
            }

            if (empty($texts)) {
                return;
            }

            shuffle($texts);
            $sample = array_slice($texts, 0, 5);

            // Retrieve already-cached translations for these texts and score them
            $cache = app(TranslationCache::class);
            $originals = [];
            $translated = [];

            foreach ($sample as $i => $text) {
                $cachedTranslation = $cache->get($text, $this->targetLang);
                if ($cachedTranslation !== null) {
                    $originals[$i] = $text;
                    $translated[$i] = $cachedTranslation;
                }
            }

            $scorer->scoreRandomSample($originals, $translated, $this->targetLang);
        } catch (\Throwable) {
            // Scoring must never fail the job
        }
    }
}

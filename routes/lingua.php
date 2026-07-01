<?php

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Jobs\LinguaAgentChangeDetectionJob;
use LinguaLayer\Jobs\LinguaAgentDiscoveryJob;
use LinguaLayer\Jobs\LinguaAgentQualityCheckJob;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Models\AgentState;
use LinguaLayer\Models\Translation;
use LinguaLayer\Services\LinguaAgent;
use LinguaLayer\Services\ProgressTracker;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslationScorer;
use LinguaLayer\Services\TranslationStore;
use LinguaLayer\Services\TranslatorFactory;

/*
|--------------------------------------------------------------------------
| LinguaLayer — Production routes
|--------------------------------------------------------------------------
| Registered by the service provider under the /lingua prefix on every env.
*/

// Input translation endpoint — called by lingua.js before form submit.
// Translates user-typed values from their UI language back to the source language.
Route::post('/translate-input', function (Request $request, TranslatorInterface $translator) {
    $sourceLang = config('lingua.source_language', 'en');
    $userLang = $request->input('source_lang', $_COOKIE['lingua_lang'] ?? $sourceLang);
    $fields = $request->input('fields', []);
    $excluded = config('lingua.excluded_fields', []);
    $skipPatterns = config('lingua.translate_field_patterns.skip', []);

    // Fail-safe out (return originals) when there is nothing to do OR the request
    // exceeds the per-request caps — so a caller can never fan one request out
    // into a huge billed batch. See config('lingua.throttle.max_*').
    $maxFields = (int) config('lingua.throttle.max_fields', 200);
    $maxBytes = (int) config('lingua.throttle.max_bytes', 100000);
    if ($userLang === $sourceLang || empty($fields) || ! is_array($fields)
        || count($fields) > $maxFields || strlen((string) json_encode($fields)) > $maxBytes) {
        return response()->json(['fields' => $fields]);
    }

    $toTranslate = [];
    $fieldNames = [];
    $skipped = [];

    foreach ($fields as $name => $value) {
        if (in_array($name, $excluded, true)) {
            $skipped[$name] = $value;

            continue;
        }

        $nameLower = strtolower((string) $name);
        $matchesSkip = false;
        foreach ($skipPatterns as $pattern) {
            if (str_contains($nameLower, strtolower($pattern))) {
                $matchesSkip = true;
                break;
            }
        }
        if ($matchesSkip) {
            $skipped[$name] = $value;

            continue;
        }

        if (! is_string($value) || mb_strlen(trim($value)) < 2) {
            $skipped[$name] = $value;

            continue;
        }

        $toTranslate[] = $value;
        $fieldNames[] = $name;
    }

    if (empty($toTranslate)) {
        return response()->json(['fields' => $fields]);
    }

    // The JS sends values typed in $userLang — we ask Gemini to translate them
    // INTO $sourceLang so the controller receives data in its native language.
    $translated = $translator->translateBatch($toTranslate, $sourceLang);

    if ($translated === null) {
        // All retries failed — never block the form submit, fall back to originals.
        return response()->json(['fields' => $fields]);
    }

    $result = $skipped;
    foreach ($fieldNames as $i => $name) {
        $result[$name] = $translated[$i] ?? $toTranslate[$i];
    }

    return response()->json(['fields' => $result]);
})
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:lingua-input')
    ->name('lingua.translate-input');

// Dynamic-DOM translation endpoint — called by lingua.js MutationObserver
// when the page mutates after initial render (SPA/Livewire/HTMX/AJAX).
// Direction: source_language → target_lang. Opposite of /translate-input.
Route::post('/translate-dom', function (Request $request, TranslatorInterface $translator) {
    $sourceLang = config('lingua.source_language', 'en');
    $targetLang = $request->input('target_lang', $request->header('X-Lingua-Lang', $sourceLang));
    $fields = $request->input('fields', []);

    $supported = array_keys(config('lingua.supported_languages', []));
    // Same per-request caps as /translate-input (cost-amplification guard).
    $maxFields = (int) config('lingua.throttle.max_fields', 200);
    $maxBytes = (int) config('lingua.throttle.max_bytes', 100000);
    if (! in_array($targetLang, $supported, true) || $targetLang === $sourceLang || ! is_array($fields) || empty($fields)
        || count($fields) > $maxFields || strlen((string) json_encode($fields)) > $maxBytes) {
        return response()->json(['fields' => (object) []]);
    }

    $keys = array_keys($fields);
    $values = array_values(array_map(fn ($v) => is_string($v) ? $v : '', $fields));

    $translated = $translator->translateBatch($values, $targetLang);
    if ($translated === null) {
        return response()->json(['fields' => (object) []]);
    }

    $result = [];
    foreach ($keys as $i => $k) {
        $result[$k] = $translated[$i] ?? $values[$i];
    }

    return response()->json(['fields' => $result]);
})
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:lingua-dom')
    ->name('lingua.translate-dom');

// Async translation status endpoint — polled by lingua.js in queue-driven mode.
Route::get('/status/{hash}', function (string $hash) {
    $driver = config('lingua.cache_driver', 'file');
    $statusKey = 'lingua_page_'.$hash.'_status';
    $status = Cache::driver($driver)->get($statusKey, 'unknown');

    return response()->json(['status' => $status]);
})->where('hash', '[a-f0-9]+')->name('lingua.status');

// Shared access gate for the quality dashboard AND its state-changing action
// endpoints. Security model (since v1.7.0):
//   • A LINGUA_QUALITY_SECRET, when set, is REQUIRED in EVERY environment
//     (passed as ?key= or a `key` field) — 403 on mismatch.
//   • With NO secret configured the surface is reachable ONLY on a genuinely
//     local dev box; on any other environment (production, staging, UAT, dev…)
//     it returns 404. An internet-reachable host therefore never exposes the
//     dashboard — or its mutating actions — unauthenticated.
$qualityGate = function (Request $request): void {
    $secret = (string) config('lingua.quality_secret', '');

    if ($secret === '') {
        if (! app()->environment('local')) {
            abort(404);
        }

        return;
    }

    $provided = (string) ($request->query('key') ?? $request->input('key') ?? '');
    if (! hash_equals($secret, $provided)) {
        abort(403, 'Provide ?key=YOUR_LINGUA_QUALITY_SECRET to access the dashboard.');
    }
};

// Quality dashboard — shows auto-scored translation samples. Access governed by
// $qualityGate above (secret required everywhere except a local dev box).
Route::get('/quality', function (Request $request, TranslationScorer $scorer, TranslationStore $store) use ($qualityGate) {
    $qualityGate($request);

    // The dashboard calls verifyLicense() and getUsage() on the Gateway —
    // both can take seconds while a warm worker is hammering the same
    // backend. The default 30s max_execution_time is too tight for the
    // worst case; we lift it for this view only.
    @set_time_limit(0);

    $stats = $scorer->getStats();
    $index = $scorer->getIndex();

    // Persistent-store stats (Pilar 1.9). When the BD is unavailable or empty,
    // these stay at zero — the dashboard renders the section with empty state.
    $storeStats = $store->stats();

    $recent = $store->isAvailable()
        ? Translation::query()
            ->where('is_obsolete', false)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
        : collect();

    // Counters from the cache layer (lifetime totals, not per-day)
    $cacheCounters = [
        'fragments_total' => TranslationCache::readStat(TranslationCache::STATS_FRAGMENTS_TOTAL),
        'pages_total' => TranslationCache::readStat(TranslationCache::STATS_PAGES_TOTAL),
        'hits_total' => TranslationCache::readStat(TranslationCache::STATS_HITS_TOTAL),
        'calls_total' => TranslationCache::readStat(TranslationCache::STATS_CALLS_TOTAL),
        'last_warm' => Cache::driver(config('lingua.cache_driver', 'file'))
            ->get(TranslationCache::STATS_LAST_WARM),
    ];
    $totalLookups = $cacheCounters['hits_total'] + $cacheCounters['calls_total'];
    $cacheCounters['coverage_pct'] = $totalLookups > 0
        ? round(($cacheCounters['hits_total'] / $totalLookups) * 100, 1)
        : 0.0;

    // Pilar 3.10: dual-mode awareness in the dashboard. Cache-only path so
    // the page render NEVER waits on the Gateway — when a warm worker is
    // hammering the same backend, that HTTP call can stall for 30s+ and
    // turn the dashboard into a fatal-error page. If the cache is empty
    // we render with verified=null and the JS poll will eventually fill
    // in updated values once the Gateway answers in the background.
    $mode = TranslatorFactory::detectMode();
    $gateway = null;
    if ($mode === 'gateway') {
        $cacheDriver = config('lingua.cache_driver', 'file');
        $licCache = Cache::driver($cacheDriver)->get('lingua_gateway_license_valid');
        $usageCache = Cache::driver($cacheDriver)->get('lingua_gateway_usage');

        $gateway = [
            'url' => (string) config('lingua.gateway.url', ''),
            'usage' => $usageCache,
            'verified' => $licCache !== null ? (bool) ($licCache['valid'] ?? false) : null,
        ];
    }

    // Pilar 5.9: agent state for the progress panel that lives at the top
    // of the dashboard. We try Schema::hasTable first, but if MySQL is busy
    // (a warm worker pounding the connection pool) hasTable can timeout and
    // we'd render a misleading "NOT INSTALLED" badge. Fall back to a direct
    // SELECT with a tiny timeout — if the table genuinely doesn't exist we
    // treat any error as "not installed".
    $agentState = null;
    try {
        $row = AgentState::query()->find(1);
        if (! $row) {
            // table exists but row missing — create it on the fly
            $row = AgentState::singleton();
        }
        $agentState = [
            'enabled' => $row->enabled && (bool) config('lingua.agent.enabled', false),
            'last_full_scan_at' => $row->last_full_scan_at?->diffForHumans(),
            'last_change_check_at' => $row->last_change_check_at?->diffForHumans(),
            'pages_known' => (int) $row->pages_known,
        ];
    } catch (Throwable $e) {
        // Real "table missing" lands here. Log so we can tell apart the
        // genuine missing-table case from a transient connection error.
        Log::channel('single')->warning('[LinguaLayer][dashboard] agentState fetch failed', [
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ]);
    }

    /** @var ProgressTracker $tracker */
    $tracker = app(ProgressTracker::class);
    $agentProgress = $tracker->getProgress();
    $agentOverall = $tracker->getOverallProgress();
    $agentEvents = $tracker->getNotifications(10);

    return view('lingua::quality', compact(
        'stats', 'index', 'storeStats', 'recent', 'cacheCounters', 'mode', 'gateway',
        'agentState', 'agentProgress', 'agentOverall', 'agentEvents'
    ));
})->name('lingua.quality');

// Pilar 5.13: dashboard action endpoints — drive the agent from the UI without
// leaving the browser. Governed by the same $qualityGate as /quality (secret
// required outside local), so random visitors can't trigger scans or queue work.
Route::post('/quality/action/scan', function (Request $request) use ($qualityGate) {
    $qualityGate($request);

    // Skip the discovery short-circuit and dispatch warm jobs directly. The
    // user clicked "Scan & translate now" expecting to see the bars move,
    // even when most pages are already cached — recording every page as
    // "completed" toward the progress total is exactly the visible signal
    // they want. The force flag ensures already-cached pages still flow
    // through the tracker, with $force optionally rebuilding the cache.
    $force = (bool) $request->boolean('force', false);

    $supported = array_keys((array) config('lingua.supported_languages', []));
    $source = (string) config('lingua.source_language', 'en');
    $targets = array_values(array_diff($supported, [$source]));

    if (empty($targets)) {
        return response()->json(['ok' => false, 'message' => 'No target languages configured.'], 400);
    }

    /** @var ProgressTracker $tracker */
    $tracker = app(ProgressTracker::class);

    // Initialize the bars eagerly so the dashboard sees rows the very next
    // poll, instead of waiting for the worker to start the warm job.
    $count = 0;
    try {
        $agent = app(LinguaAgent::class);
        $count = count($agent->discoverRoutes());
    } catch (Throwable) {
        // fallthrough — warm job will compute its own URL list
    }

    foreach ($targets as $lang) {
        $tracker->initializeForLanguage($lang, max($count, 1));

        WarmAllPagesJob::dispatch([$lang], null, $force)
            ->onQueue(config('lingua.queue_name', 'lingua'));
    }

    return response()->json([
        'ok' => true,
        'message' => 'Warm jobs queued for '.count($targets).' language(s). Watch the bars.',
        'languages' => $targets,
        'pages' => $count,
    ]);
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.scan');

Route::post('/quality/action/check-changes', function (Request $request) use ($qualityGate) {
    $qualityGate($request);
    LinguaAgentChangeDetectionJob::dispatch()
        ->onQueue(config('lingua.queue_name', 'lingua'));

    return response()->json([
        'ok' => true,
        'message' => 'Change-detection job queued.',
    ]);
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.check-changes');

Route::post('/quality/action/enable', function (Request $request) use ($qualityGate) {
    $qualityGate($request);
    try {
        if (! Schema::hasTable('lingua_agent_state')) {
            return response()->json(['ok' => false, 'message' => 'Agent table missing — run php artisan migrate.'], 500);
        }
        AgentState::singleton()->forceFill(['enabled' => true])->save();

        return response()->json(['ok' => true, 'message' => 'Agent enabled.']);
    } catch (Throwable $e) {
        return response()->json(['ok' => false, 'message' => 'Database unreachable: '.$e->getMessage()], 500);
    }
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.enable');

Route::post('/quality/action/disable', function (Request $request) use ($qualityGate) {
    $qualityGate($request);
    try {
        if (! Schema::hasTable('lingua_agent_state')) {
            return response()->json(['ok' => false, 'message' => 'Agent table missing.'], 500);
        }
        AgentState::singleton()->forceFill(['enabled' => false])->save();

        return response()->json(['ok' => true, 'message' => 'Agent disabled.']);
    } catch (Throwable $e) {
        return response()->json(['ok' => false, 'message' => 'Database unreachable: '.$e->getMessage()], 500);
    }
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.disable');

Route::post('/quality/action/quality-check', function (Request $request) use ($qualityGate) {
    $qualityGate($request);
    LinguaAgentQualityCheckJob::dispatch()
        ->onQueue(config('lingua.queue_name', 'lingua'));

    return response()->json(['ok' => true, 'message' => 'Quality re-evaluation job queued.']);
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.quality-check');

Route::post('/quality/action/clear-cache', function (Request $request) use ($qualityGate) {
    $qualityGate($request);
    $driver = config('lingua.cache_driver', 'file');
    try {
        // NEVER flush() the whole store — that would wipe the HOST app's own
        // cached data (and any cache-backed sessions/locks/rate-limiters). We
        // forget only LinguaLayer's own well-known keys; the content-addressed
        // per-page and per-fragment entries (lingua_*, lingua_page_*) are not
        // enumerable across drivers and expire by TTL. For an immediate full
        // rebuild use "Scan & translate now" (force) or `php artisan cache:clear`.
        $cache = Cache::driver($driver);
        foreach ([
            TranslationCache::STATS_FRAGMENTS_TOTAL,
            TranslationCache::STATS_PAGES_TOTAL,
            TranslationCache::STATS_HITS_TOTAL,
            TranslationCache::STATS_CALLS_TOTAL,
            TranslationCache::STATS_LAST_WARM,
            'lingua_score_index',
        ] as $key) {
            $cache->forget($key);
        }

        return response()->json([
            'ok' => true,
            'message' => 'LinguaLayer counters cleared. Per-page entries expire by TTL — use "Scan & translate now" to rebuild immediately.',
        ]);
    } catch (Throwable $e) {
        return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
    }
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.clear-cache');

/**
 * Self-driving queue: processes pending jobs from the lingua queue inline,
 * up to N seconds per call. The dashboard JS hits this every 5s while the
 * page is open — so the queue drains itself without any external worker
 * (queue:work) running. Closes the loop: open the dashboard → click → see
 * progress → done. No terminal, no scheduler, no background process.
 *
 * Returns counters so the dashboard JS can reflect activity.
 */
Route::post('/quality/action/process-queue', function (Request $request) use ($qualityGate) {
    $qualityGate($request);

    // Big jobs (WarmAllPagesJob can iterate dozens of URLs × multiple langs)
    // routinely outrun PHP's default 30s max_execution_time when run inline
    // from an HTTP request. Lift the cap for this closure only — the outer
    // time-budget below already bounds how long we keep the connection open.
    @set_time_limit(0);

    $maxSeconds = (int) min(8, max(1, $request->input('max_seconds', 4)));

    $started = microtime(true);
    $processed = 0;
    $failed = 0;

    // Drain the queue inline. Bail gracefully if the configured queue driver
    // doesn't implement worker semantics (e.g. 'sync' executes on dispatch
    // and has no pending pool to pop from) — that case isn't an error, just
    // nothing to do this tick.
    //
    // SAFETY (2026-04-27): we used to fire EVERY job inline, which let a
    // single WarmAllPagesJob (hundreds of pages × many gateway calls) pin
    // an Apache thread for minutes. With multiple browsers/tabs polling at
    // 5s each, the http worker pool exhausted and dependent inserts to the
    // gateway's usage_logs piled up until MySQL ran out of connections.
    // The dashboard now only fires "small" jobs inline (TranslatePageJob,
    // SendCompletionNotificationJob, LinguaAgentChangeDetectionJob,
    // LinguaAgentQualityCheckJob) and releases anything heavier back to
    // the queue for the dedicated worker to pick up.
    $heavyJobs = [
        WarmAllPagesJob::class,
        LinguaAgentDiscoveryJob::class,
    ];
    try {
        $manager = app(Factory::class);
        $connection = $manager->connection(config('queue.default'));

        while ((microtime(true) - $started) < $maxSeconds) {
            try {
                $job = $connection->pop(config('lingua.queue_name', 'lingua'));
            } catch (Throwable) {
                // Driver doesn't support pop (sync) — treat as empty queue.
                break;
            }
            if ($job === null) {
                break;
            }

            // Quick payload sniff — release heavy job back to queue without
            // executing inline. The dedicated worker (or a future tick when
            // the dedicated worker isn't running) will fire it.
            $payload = $job->payload();
            $name = $payload['displayName'] ?? '';
            if (in_array($name, $heavyJobs, true)) {
                $job->release(0); // requeue immediately, no delay
                break;            // and stop this tick to avoid hot loop
            }

            try {
                $job->fire();
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                if (! $job->isDeleted() && ! $job->isReleased()) {
                    $job->fail($e);
                }
            }
        }
    } catch (Throwable $e) {
        return response()->json([
            'ok' => false,
            'message' => 'Worker error: '.$e->getMessage(),
            'processed' => $processed,
            'failed' => $failed,
        ], 500);
    }

    return response()->json([
        'ok' => true,
        'processed' => $processed,
        'failed' => $failed,
        'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
    ]);
})->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lingua.quality.action.process-queue');

// Pilar 5.10: lightweight JSON endpoint polled by the dashboard JS every few
// seconds. Same secret-protection rules as /quality so progress is not
// publicly visible. Returns the same shape the Blade view consumed at first
// render, so the JS only has to swap text + width values in place.
Route::get('/quality/progress', function (Request $request, ProgressTracker $tracker) use ($qualityGate) {
    $qualityGate($request);

    $agentState = null;
    try {
        if (Schema::hasTable('lingua_agent_state')) {
            $row = AgentState::singleton();
            $agentState = [
                'enabled' => $row->enabled && (bool) config('lingua.agent.enabled', false),
                'last_full_scan_at' => $row->last_full_scan_at?->toIso8601String(),
                'last_change_check_at' => $row->last_change_check_at?->toIso8601String(),
                'pages_known' => (int) $row->pages_known,
            ];
        }
    } catch (Throwable) {
        // empty
    }

    return response()->json([
        'agent' => $agentState,
        'languages' => $tracker->getProgress(),
        'overall' => $tracker->getOverallProgress(),
        'notifications' => $tracker->getNotifications(10),
        'generated_at' => now()->toIso8601String(),
    ]);
})->name('lingua.quality.progress');

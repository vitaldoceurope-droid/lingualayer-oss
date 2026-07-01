<?php

namespace LinguaLayer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\ProgressTracker;
use LinguaLayer\Services\TranslationCache;

/**
 * Pre-translate every public GET route × every supported language so end users
 * never wait on first visit. Runs in the background; per-route failures never
 * abort the whole sweep — we want maximum coverage, not all-or-nothing.
 */
class WarmAllPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  array<int,string>|null  $langs  Restrict to specific target langs (null = all supported except source)
     * @param  array<int,string>|null  $extraUrls  Extra paths/URLs to include alongside auto-discovered routes
     * @param  bool  $force  Re-translate even if a page is already cached
     */
    public function __construct(
        private readonly ?array $langs = null,
        private readonly ?array $extraUrls = null,
        private readonly bool $force = false,
    ) {}

    public function handle(HttpKernel $kernel, HtmlTranslator $translator, ProgressTracker $tracker): void
    {
        $source = config('lingua.source_language', 'en');
        $supported = array_keys(config('lingua.supported_languages', []));

        $langs = $this->langs !== null
            ? array_values(array_intersect($this->langs, $supported))
            : array_values(array_diff($supported, [$source]));
        $langs = array_values(array_diff($langs, [$source]));

        if (empty($langs)) {
            Log::channel('single')->info('[LinguaLayer] WarmAllPagesJob: no target languages — skipping');

            return;
        }

        $urls = $this->collectUrls();

        if (empty($urls)) {
            Log::channel('single')->info('[LinguaLayer] WarmAllPagesJob: no URLs discovered — skipping');

            return;
        }

        // Enforce the documented safety cap so a run is always bounded — this
        // is what keeps the opportunistic (cron-less) tick light on shared
        // hosts. Never silently drop: log what was skipped this run.
        $maxPages = (int) config('lingua.agent.max_pages_per_run', 50);
        if ($maxPages > 0 && count($urls) > $maxPages) {
            Log::channel('single')->info('[LinguaLayer] WarmAllPagesJob: capping to '.$maxPages.' of '.count($urls).' pages this run (max_pages_per_run)');
            $urls = array_slice($urls, 0, $maxPages);
        }

        // Initialize progress per language so the dashboard renders bars
        // immediately, even on the first page. Calling initializeForLanguage
        // again on a language already running re-bases the counters — that
        // matches the contract of the agent jobs that re-dispatch warm runs.
        foreach ($langs as $lang) {
            $tracker->initializeForLanguage($lang, count($urls));
        }

        $driver = config('lingua.cache_driver', 'file');
        $ttl = (int) config('lingua.cache_ttl', 86400) * 60;

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($urls as $url) {
            foreach ($langs as $lang) {
                $absolute = $this->absolute($url);
                $key = 'lingua_page_'.md5(rtrim($absolute, '/').$lang);

                if (! $this->force && Cache::driver($driver)->has($key)) {
                    $skipped++;
                    // Skipped pages still count as "done" toward the progress
                    // total so the bar reflects work already covered by cache.
                    $tracker->recordPageCompleted($lang, $url, 0);

                    continue;
                }

                try {
                    $html = $this->fetchHtml($kernel, $url, $lang);
                    if ($html === null) {
                        $failed++;
                        $tracker->recordPageFailed($lang, $url);

                        continue;
                    }

                    $translated = $translator->translate($html, $lang);
                    if ($translated === null) {
                        $failed++;
                        $tracker->recordPageFailed($lang, $url);
                        Log::channel('single')->warning("[LinguaLayer] Warm: translation failed {$lang} {$url}");

                        continue;
                    }

                    if (Cache::driver($driver)->add($key, $translated, $ttl)) {
                        TranslationCache::bumpStat(TranslationCache::STATS_PAGES_TOTAL);
                    } else {
                        Cache::driver($driver)->put($key, $translated, $ttl);
                    }
                    $ok++;

                    // Approximate fragment count by counting top-level text-bearing tags.
                    $fragmentsCount = preg_match_all(
                        '#<(p|h1|h2|h3|h4|h5|h6|li|td|button|a|label|span)\b#i',
                        $translated
                    );
                    $tracker->recordPageCompleted($lang, $url, (int) $fragmentsCount);

                    Log::channel('single')->info("[LinguaLayer] Warmed: {$url} → {$lang}");
                } catch (\Throwable $e) {
                    $failed++;
                    $tracker->recordPageFailed($lang, $url);
                    Log::channel('single')->warning('[LinguaLayer] Warm error', [
                        'url' => $url,
                        'lang' => $lang,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // After everything, fire completion notifications for languages that
        // reached 100% during this run. The tracker already pushed per-lang
        // notifications; here we cover the "all langs done" banner.
        $overall = $tracker->getOverallProgress();
        if ($overall['all_completed'] ?? false) {
            SendCompletionNotificationJob::dispatch(null, true, $overall)
                ->onQueue(config('lingua.queue_name', 'lingua'));
        }

        Cache::driver($driver)->put(
            TranslationCache::STATS_LAST_WARM,
            now()->toDateTimeString(),
            86400 * 60 * 60
        );

        Log::channel('single')->info('[LinguaLayer] WarmAllPagesJob complete', [
            'ok' => $ok,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function collectUrls(): array
    {
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            // Skip parameterised routes — we cannot guess valid values
            if (str_contains($uri, '{')) {
                continue;
            }
            if ($this->isExcluded($uri)) {
                continue;
            }
            $urls[] = '/'.ltrim($uri, '/');
        }

        foreach ((array) config('lingua.warm_urls', []) as $u) {
            $urls[] = $u;
        }

        foreach ((array) ($this->extraUrls ?? []) as $u) {
            $urls[] = $u;
        }

        return array_values(array_unique(array_filter($urls, fn ($u) => $u !== '')));
    }

    private function isExcluded(string $uri): bool
    {
        $patterns = config('lingua.excluded_routes', []);
        if (empty($patterns)) {
            return false;
        }

        $path = ltrim($uri, '/');
        foreach ($patterns as $p) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote(ltrim($p, '/'), '#')).'$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    private function absolute(string $url): string
    {
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }

        // Never default to localhost: prefer APP_URL, and only fall back to the
        // live request host when there is one. In CLI/queue context request() is
        // null, so it must not be dereferenced.
        $appUrl = (string) config('app.url');
        $host = $appUrl === '' && ! app()->runningInConsole()
            ? request()->getSchemeAndHttpHost()
            : '';
        $base = rtrim($appUrl !== '' ? $appUrl : $host, '/');

        return $base.'/'.ltrim($url, '/');
    }

    private function fetchHtml(HttpKernel $kernel, string $url, string $lang): ?string
    {
        $path = preg_match('#^https?://#', $url) ? parse_url($url, PHP_URL_PATH) : $url;
        $path = '/'.ltrim($path ?? '/', '/');

        // Pass an absolute URL so Symfony Request::create() honours the host
        // app's APP_URL instead of falling back to "http://localhost". Without
        // this, $kernel->handle() rendered HTML where url()/route()/asset()
        // emitted localhost-based absolute URLs; that HTML got cached and
        // served to real users in non-source languages, sending them to a
        // local Apache/XAMPP on subsequent navigation.
        $base = rtrim(config('app.url', ''), '/');
        $absolute = $base !== '' ? $base.$path : $path;

        $request = Request::create($absolute, 'GET');
        $request->cookies->set('lingua_lang', $lang);
        $request->headers->set('X-Lingua-Lang', $lang);
        $request->headers->set('Accept', 'text/html');

        $response = $kernel->handle($request);

        $status = $response->getStatusCode();
        $contentType = $response->headers->get('Content-Type', '');

        if ($status >= 400 || ! str_contains($contentType, 'text/html')) {
            return null;
        }

        $body = $response->getContent();
        $kernel->terminate($request, $response);

        return $body ?: null;
    }
}

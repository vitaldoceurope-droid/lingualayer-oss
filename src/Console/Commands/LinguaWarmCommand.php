<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationCache;

class LinguaWarmCommand extends Command
{
    protected $signature = 'lingua:warm
        {--langs= : Comma-separated target languages (default: all supported except source)}
        {--urls= : Comma-separated extra URLs to warm (paths or absolute)}
        {--only-urls : Skip route auto-discovery; warm only --urls and config warm_urls}
        {--force : Overwrite existing page cache entries}
        {--detect-new : Only warm routes whose page cache is missing for at least one target language}
        {--max-seconds= : Stop after roughly N seconds and resume next run (for cron-driven warming without a worker)}
        {--dry-run : List what would be warmed without calling Gemini}';

    protected $description = 'Pre-translate and cache all public pages for every supported language so end users never wait on first visit.';

    public function handle(HttpKernel $kernel, HtmlTranslator $translator): int
    {
        $source = config('lingua.source_language', 'en');
        $supported = array_keys(config('lingua.supported_languages', []));

        $langs = $this->option('langs')
            ? array_map('trim', explode(',', $this->option('langs')))
            : array_values(array_diff($supported, [$source]));

        $langs = array_values(array_intersect($langs, $supported));
        $langs = array_values(array_diff($langs, [$source]));

        if (empty($langs)) {
            $this->error('No target languages to warm. Check supported_languages and --langs.');

            return self::FAILURE;
        }

        $urls = $this->collectUrls();

        if (empty($urls)) {
            $this->error('No URLs discovered. Add paths to config lingua.warm_urls or pass --urls.');

            return self::FAILURE;
        }

        if ($this->option('detect-new')) {
            $driver = config('lingua.cache_driver', 'file');
            $before = count($urls);
            $urls = array_values(array_filter($urls, function ($u) use ($driver, $langs) {
                $absolute = $this->absolute($u);
                foreach ($langs as $l) {
                    $key = 'lingua_page_'.md5(rtrim($absolute, '/').$l);
                    if (! Cache::driver($driver)->has($key)) {
                        return true;
                    }
                }

                return false;
            }));
            $this->line(sprintf(
                '<fg=blue>--detect-new</>: %d/%d URL(s) have at least one missing language.',
                count($urls),
                $before
            ));
            if (empty($urls)) {
                $this->info('Nothing new to warm.');

                return self::SUCCESS;
            }
        }

        $this->info(sprintf(
            'Warming %d URL(s) × %d language(s) = %d page(s).',
            count($urls),
            count($langs),
            count($urls) * count($langs)
        ));

        if ($this->option('dry-run')) {
            foreach ($urls as $u) {
                foreach ($langs as $l) {
                    $this->line("  [dry] {$l}  {$u}");
                }
            }

            return self::SUCCESS;
        }

        $driver = config('lingua.cache_driver', 'file');
        $ttl = (int) config('lingua.cache_ttl', 86400) * 60;
        $force = (bool) $this->option('force');
        $maxSeconds = (int) $this->option('max-seconds');
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : null;

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar(count($urls) * count($langs));
        $bar->start();

        foreach ($urls as $url) {
            foreach ($langs as $lang) {
                // Time-budget guard for cron-driven warming without a worker:
                // stop cleanly when the budget is spent; the next run resumes
                // from the still-uncached pages (skip-cached below).
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $this->newLine();
                    $this->line('  <fg=yellow>time budget reached</> — stopping; remaining pages warm on the next run.');
                    break 2;
                }

                $absolute = $this->absolute($url);
                $key = 'lingua_page_'.md5(rtrim($absolute, '/').$lang);

                if (! $force && Cache::driver($driver)->has($key)) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                try {
                    $html = $this->fetchHtml($kernel, $url, $lang);
                    if ($html === null) {
                        $failed++;
                        $this->newLine();
                        $this->warn("  ✗ non-HTML or error response  {$lang}  {$url}");
                        $bar->advance();

                        continue;
                    }

                    $translated = $translator->translate($html, $lang);
                    if ($translated === null) {
                        $failed++;
                        $this->newLine();
                        $this->warn("  ✗ translation failed  {$lang}  {$url}");
                        $bar->advance();

                        continue;
                    }

                    if (Cache::driver($driver)->add($key, $translated, $ttl)) {
                        TranslationCache::bumpStat(TranslationCache::STATS_PAGES_TOTAL);
                    } else {
                        Cache::driver($driver)->put($key, $translated, $ttl);
                    }
                    $ok++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("  ✗ {$e->getMessage()}  {$lang}  {$url}");
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        Cache::driver($driver)->put(
            TranslationCache::STATS_LAST_WARM,
            now()->toDateTimeString(),
            86400 * 60 * 60
        );

        $this->info("Cached:  {$ok}");
        $this->line("Skipped: {$skipped} (already cached — pass --force to rebuild)");
        if ($failed > 0) {
            $this->warn("Failed:  {$failed}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Merge auto-discovered GET routes (no params), config warm_urls, and --urls.
     *
     * @return array<int,string>
     */
    private function collectUrls(): array
    {
        $urls = [];

        if (! $this->option('only-urls')) {
            foreach (Route::getRoutes() as $route) {
                if (! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                $uri = $route->uri();
                if (str_contains($uri, '{')) {
                    continue;
                }
                if ($this->isExcluded($uri)) {
                    continue;
                }
                $urls[] = '/'.ltrim($uri, '/');
            }
        }

        foreach ((array) config('lingua.warm_urls', []) as $u) {
            $urls[] = $u;
        }

        if ($this->option('urls')) {
            foreach (explode(',', $this->option('urls')) as $u) {
                $urls[] = trim($u);
            }
        }

        $urls = array_values(array_unique(array_filter($urls, fn ($u) => $u !== '')));

        return $urls;
    }

    private function isExcluded(string $uri): bool
    {
        $patterns = config('lingua.excluded_routes', []);
        if (empty($patterns)) {
            return false;
        }

        $path = '/'.ltrim($uri, '/');
        foreach ($patterns as $p) {
            $regex = '#^/?'.str_replace('\*', '.*', preg_quote(ltrim($p, '/'), '#')).'$#';
            if (preg_match($regex, ltrim($path, '/'))) {
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

    /**
     * Dispatch an internal request through Laravel's HTTP kernel so middleware
     * (including SecurityHeaders, session, auth-optional public routes) runs
     * exactly as it would for a real visitor — but without a network hop.
     */
    private function fetchHtml(HttpKernel $kernel, string $url, string $lang): ?string
    {
        $path = preg_match('#^https?://#', $url) ? parse_url($url, PHP_URL_PATH) : $url;
        $path = '/'.ltrim($path ?? '/', '/');

        // Pass an ABSOLUTE URL so Request::create() honours the host app's
        // APP_URL instead of defaulting to "http://localhost". Without this the
        // rendered HTML's url()/route()/asset() emit localhost links, which then
        // get cached and served to real users (sending them to localhost).
        $base = rtrim((string) config('app.url', ''), '/');
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

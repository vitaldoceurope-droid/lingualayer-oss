<?php

namespace LinguaLayer\Services;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LinguaLayer\Models\AgentState;
use LinguaLayer\Models\Translation;

/**
 * Autonomous agent that decides when to scan for new pages, when to look for
 * content changes on existing pages, and when to re-evaluate translation
 * quality. Stateful via the lingua_agent_state row.
 *
 * Defensive by design: when the BD tables are missing or the connection is
 * down, every method returns a safe empty result instead of throwing — the
 * scheduled jobs that wrap these calls keep running on the next tick.
 */
class LinguaAgent
{
    /**
     * Defaults to common framework prefixes so the agent never tries to warm
     * /admin or /telescope. Merged with config('lingua.excluded_routes').
     */
    private const HARDCODED_EXCLUDED_PREFIXES = [
        'api/*',
        'lingua/*',
        'admin/*',
        'telescope/*',
        'horizon/*',
        '_debugbar/*',
        '_ignition/*',
        'sanctum/*',
        'livewire/*',
    ];

    public function __construct(private readonly TranslationStore $store) {}

    /**
     * Discovery: list every public GET route the host app exposes that the
     * agent should consider as a translation target.
     *
     * @return array<int,string> paths beginning with '/'
     */
    public function discoverRoutes(): array
    {
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Cannot guess valid values for required parameters.
            if (str_contains($uri, '{')) {
                continue;
            }

            if ($this->isExcluded($uri)) {
                continue;
            }

            $urls[] = '/'.ltrim($uri, '/');
        }

        // Dedupe + stable order so the signature is deterministic.
        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    /**
     * SHA256 over the sorted route list. Stable across processes — used to
     * detect that a deploy added or removed routes.
     */
    public function calculateRoutesSignature(array $routes): string
    {
        sort($routes);

        return hash('sha256', implode("\n", $routes));
    }

    /**
     * A full scan is needed when:
     *   - the agent has never run (no last_full_scan_at), OR
     *   - the route signature changed since the last run, OR
     *   - more than the configured discovery interval has elapsed.
     */
    public function needsFullScan(): bool
    {
        if (! $this->stateAvailable()) {
            return false;
        }

        $state = AgentState::singleton();
        $routes = $this->discoverRoutes();
        $sig = $this->calculateRoutesSignature($routes);

        if ($state->last_full_scan_at === null) {
            return true;
        }

        if ($state->routes_signature !== $sig) {
            return true;
        }

        $intervalHours = (int) config('lingua.agent.discovery_interval_hours', 6);

        return $state->last_full_scan_at->lt(now()->subHours($intervalHours));
    }

    public function needsChangeCheck(): bool
    {
        if (! $this->stateAvailable()) {
            return false;
        }

        $state = AgentState::singleton();
        if ($state->last_change_check_at === null) {
            return true;
        }

        $intervalHours = (int) config('lingua.agent.change_check_interval_hours', 1);

        return $state->last_change_check_at->lt(now()->subHours($intervalHours));
    }

    /**
     * For every discovered route, render it in the source language and figure
     * out whether the page contains text the persistent store has not seen
     * yet. Result is the URL list the warm jobs should target.
     *
     * @return array<int,string>
     */
    public function scanForNewPages(HttpKernel $kernel): array
    {
        $routes = $this->discoverRoutes();
        if (empty($routes) || ! $this->store->isAvailable()) {
            return [];
        }

        $newPageUrls = [];
        $sourceLang = (string) config('lingua.source_language', 'en');

        foreach ($routes as $url) {
            try {
                $html = $this->fetchHtml($kernel, $url, $sourceLang);
                if ($html === null) {
                    continue;
                }

                if ($this->pageHasUnknownFragments($html, $sourceLang)) {
                    $newPageUrls[] = $url;
                }
            } catch (\Throwable $e) {
                Log::channel('single')->debug('[LinguaLayer][agent] scan error', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update agent state once at the end.
        if ($this->stateAvailable()) {
            $state = AgentState::singleton();
            $state->forceFill([
                'last_full_scan_at' => now(),
                'pages_known' => count($routes),
                'routes_signature' => $this->calculateRoutesSignature($routes),
            ])->save();
        }

        return $newPageUrls;
    }

    /**
     * Re-visit pages already known to the store and detect text fragments
     * that changed since the last translation cycle.
     *
     * @return array<int,string> list of URLs whose source content drifted
     */
    public function checkForChanges(HttpKernel $kernel): array
    {
        if (! $this->store->isAvailable()) {
            return [];
        }

        $sourceLang = (string) config('lingua.source_language', 'en');

        $knownUrls = Translation::query()
            ->whereNotNull('page_url')
            ->where('is_obsolete', false)
            ->distinct()
            ->pluck('page_url')
            ->filter()
            ->values()
            ->all();

        $changed = [];
        foreach ($knownUrls as $absUrl) {
            $path = parse_url($absUrl, PHP_URL_PATH) ?: $absUrl;
            try {
                $html = $this->fetchHtml($kernel, $path, $sourceLang);
                if ($html === null) {
                    continue;
                }
                if ($this->pageHasUnknownFragments($html, $sourceLang)) {
                    $changed[] = $path;
                }
            } catch (\Throwable $e) {
                Log::channel('single')->debug('[LinguaLayer][agent] change-check error', [
                    'url' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->stateAvailable()) {
            AgentState::singleton()->forceFill([
                'last_change_check_at' => now(),
            ])->save();
        }

        return array_values(array_unique($changed));
    }

    /**
     * Render the page with a synthetic internal request. Returns the HTML
     * body or null if the response was not a successful HTML document.
     */
    private function fetchHtml(HttpKernel $kernel, string $url, string $lang): ?string
    {
        $path = preg_match('#^https?://#', $url)
            ? (parse_url($url, PHP_URL_PATH) ?: '/')
            : $url;
        $path = '/'.ltrim($path, '/');

        $request = Request::create($path, 'GET');
        $request->cookies->set('lingua_lang', $lang);
        $request->headers->set('X-Lingua-Lang', $lang);
        $request->headers->set('Accept', 'text/html');

        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $type = $response->headers->get('Content-Type', '');

        if ($status >= 400 || ! str_contains($type, 'text/html')) {
            return null;
        }

        $body = $response->getContent();
        $kernel->terminate($request, $response);

        return $body ?: null;
    }

    /**
     * Cheap content-drift heuristic: rip text-bearing tags out of the HTML and
     * ask the store whether each fragment is already known. The first miss
     * means the page should be (re)translated.
     */
    private function pageHasUnknownFragments(string $html, string $sourceLang): bool
    {
        if (preg_match_all('#<(p|h1|h2|h3|h4|h5|h6|li|td|button|a|label|span)\b[^>]*>([^<]+)</\1>#i', $html, $m)) {
            foreach ($m[2] as $raw) {
                $text = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (mb_strlen($text) < 3) {
                    continue;
                }
                if (preg_match('/^[\d\s\W]+$/u', $text)) {
                    continue;
                }

                // Treat any one supported target lang as a probe — if the
                // store has a translation for any lang, the source has been
                // seen before. We use the first non-source supported lang.
                $supported = array_keys((array) config('lingua.supported_languages', []));
                $probe = null;
                foreach ($supported as $l) {
                    if ($l !== $sourceLang) {
                        $probe = $l;
                        break;
                    }
                }
                if ($probe === null) {
                    continue;
                }

                if ($this->store->find($text, $sourceLang, $probe) === null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isExcluded(string $uri): bool
    {
        // Two layers of excludes:
        //   • lingua.excluded_routes — middleware skips ENTIRELY (no selector,
        //     no translation, no inject). Pure pass-through. Use only for API.
        //   • lingua.agent.excluded_routes — warmer skips, but the page still
        //     gets the selector + can translate inline if visited. Use for
        //     auth-required pages where the warmer can't auth but the user
        //     should still be able to switch language.
        $patterns = array_merge(
            self::HARDCODED_EXCLUDED_PREFIXES,
            (array) config('lingua.excluded_routes', []),
            (array) config('lingua.agent.excluded_routes', []),
        );

        $path = ltrim($uri, '/');
        foreach ($patterns as $p) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote(ltrim($p, '/'), '#')).'$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    private function stateAvailable(): bool
    {
        try {
            return Schema::hasTable('lingua_agent_state');
        } catch (\Throwable) {
            return false;
        }
    }
}

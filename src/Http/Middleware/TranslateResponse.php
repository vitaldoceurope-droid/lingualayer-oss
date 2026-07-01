<?php

namespace LinguaLayer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Jobs\TranslatePageJob;
use LinguaLayer\Jobs\WarmAllPagesJob;
use LinguaLayer\Services\GatewayClient;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationCache;
use LinguaLayer\Services\TranslatorFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslateResponse
{
    public function __construct(private HtmlTranslator $htmlTranslator) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('lingua.translate_response', true)) {
            return $next($request);
        }

        // Respect the client's excluded_routes config — zero-touch opt-out.
        // Clients with /admin/*, /webhooks/*, etc. can exclude without code changes.
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }

        // Never transform AJAX / JSON / XHR endpoints — they are data, not views.
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return $next($request);
        }

        $targetLang = $this->detectLanguage($request);
        $sourceLang = config('lingua.source_language', 'en');
        $needsTranslation = ($targetLang !== $sourceLang && ! empty($targetLang));

        // Page cache is unsafe for authenticated requests because the cached
        // HTML carries that session's CSRF token. Serving it to a different
        // session (or even a re-logged version of the same user) yields 419
        // mismatches → "session expired" redirects. Authenticated requests
        // still benefit from the fragment-level cache via HtmlTranslator.
        $isAuthenticated = $this->requestHasAuthSession($request);

        // Page cache hit — serve immediately, no controller or translator needed.
        // FIX 2026-04-27: even un-authenticated visitors carry a per-session
        // CSRF token via the `_token` hidden input on any form. Reusing a
        // cached HTML across sessions feeds them someone else's token and
        // every form submit fails 419. We therefore require the cached HTML
        // to NOT contain a _token input before serving it.
        if ($needsTranslation && $request->isMethod('GET') && ! $isAuthenticated) {
            $cached = $this->getPageCache($request, $targetLang);
            if ($cached !== null && ! $this->htmlContainsCsrfToken($cached)) {
                $cachedResponse = response($cached, 200)->header('Content-Type', 'text/html; charset=UTF-8');
                $this->applyBrowserCacheHeader($cachedResponse);

                return $cachedResponse;
            }
        }

        $response = $next($request);

        $contentType = $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        // Do not touch redirects, streamed responses, or HTTP errors — they may
        // already be halfway out the door or carry sensitive error payloads.
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            return $response;
        }
        if ($response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $html = $response->getContent();
        if (empty($html) || ! $this->looksLikeFullHtml($html)) {
            return $response;
        }

        // Always inject selector UI and config — even on source language
        $html = $this->injectAssets($html);

        // Tell browsers to revalidate so a content/translation update is picked
        // up immediately — visitors never see a stale cached page, and never
        // have to clear their browser cache by hand. (Covers every HTML path
        // below: source-with-selector, async, and sync.)
        $this->applyBrowserCacheHeader($response);

        // Never translate, dispatch a job for, or page-cache a non-200 response.
        // A transient 404/410/500/503 captured on a public GET would otherwise
        // be frozen by the page cache and re-served as a 200 for the full TTL
        // (default 60 days). The selector was already injected above, so error
        // pages keep the language UI; we just leave their content untouched.
        if ($response->getStatusCode() !== 200) {
            $response->setContent($html);

            return $response;
        }

        if (! $needsTranslation || ! $request->isMethod('GET')) {
            $response->setContent($html);

            return $response;
        }

        $pageHash = md5(rtrim($request->url(), '/').$targetLang);
        $pageKey = 'lingua_page_'.$pageHash;

        // Async mode: dispatch background job, return source page with polling meta
        if ($this->isAsyncMode()) {
            $driver = config('lingua.cache_driver', 'file');
            $statusKey = $pageKey.'_status';
            $status = Cache::driver($driver)->get($statusKey);

            // Authenticated requests must never be cached as a full page (CSRF
            // tokens, per-user data). We still translate inline so the user
            // sees the page in their language; we just skip the page cache.
            if ($isAuthenticated) {
                set_time_limit(0);
                $translated = $this->htmlTranslator->translate($html, $targetLang, $request->url());
                $response->setContent($translated ?? $html);

                return $response;
            }

            if (! $status || $status === 'failed') {
                Cache::driver($driver)->put($statusKey, 'queued', 3600);
                TranslatePageJob::dispatch($html, $targetLang, $pageKey)
                    ->onQueue(config('lingua.queue_name', 'lingua'));
            }

            $html = $this->injectTranslatingMeta($html, $pageHash);
            $response->setContent($html);

            return $response;
        }

        // Sync mode (default on XAMPP): translate inline, atomic guarantee
        set_time_limit(0);
        $translated = $this->htmlTranslator->translate($html, $targetLang, $request->url());

        if ($translated !== null) {
            // Skip page-level cache for authenticated requests — see comment
            // above. ALSO skip when the response embeds a per-session CSRF
            // token: caching that HTML would feed everyone else the same
            // token and every form submit would fail 419 on a fresh session.
            if (! $isAuthenticated && ! $this->htmlContainsCsrfToken($translated)) {
                $this->setPageCache($request, $targetLang, $translated);
            }
            $response->setContent($translated);
        } else {
            // All retries failed — serve source language, do not cache
            $response->setContent($html);
        }

        return $response;
    }

    /**
     * Opportunistic zero-touch pre-warm. Runs AFTER the response has been sent
     * to the visitor (terminable middleware), so it never adds latency to their
     * request. Throttled to at most once per `agent.tick_interval_minutes` via
     * an atomic cache lock, it dispatches a bounded warm run (capped by
     * `agent.max_pages_per_run`). On hosts WITHOUT cron or a queue worker the
     * job runs inline here — filling the page cache during normal traffic so
     * the next visitor of each page/language hits the warm path instead of
     * paying the cold-translate cost. Fully fail-safe: never throws.
     */
    public function terminate(Request $request, SymfonyResponse $response): void
    {
        try {
            if (! config('lingua.agent.enabled', false) || ! config('lingua.agent.tick_enabled', true)) {
                return;
            }
            // Only tick off a normal, successful HTML GET — never on POST,
            // redirects, errors or downloads.
            if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
                return;
            }
            // Don't tick when LinguaLayer isn't configured (NullTranslator).
            if (TranslatorFactory::detectMode() === 'unconfigured') {
                return;
            }
            // Only warm via the queue, and only when async is opted in — which
            // implies a real worker is running (see isAsyncMode()). Otherwise
            // the dispatched WarmAllPagesJob is never processed and just piles
            // up dead rows in the host's `jobs` table. Gateway clients (no
            // local worker) and worker-less hosts are excluded here; their
            // pre-warm happens server-side or via the cron `lingua:warm` path.
            if (! $this->isAsyncMode()) {
                return;
            }
            // Atomic throttle: the first request after the window wins the lock;
            // everyone else returns immediately. Cluster-safe on shared cache.
            $minutes = max(1, (int) config('lingua.agent.tick_interval_minutes', 30));
            $won = Cache::driver(config('lingua.cache_driver', 'file'))
                ->add('lingua_agent_tick_lock', time(), $minutes * 60);
            if (! $won) {
                return;
            }

            WarmAllPagesJob::dispatch();
        } catch (\Throwable) {
            // A pre-warm tick must never affect the host — swallow everything.
        }
    }

    /**
     * Does the rendered HTML embed a CSRF token? Hidden input or meta tag —
     * either is a smoking gun that the page is form-bearing and must NOT be
     * shared across sessions via the page cache.
     */
    private function htmlContainsCsrfToken(string $html): bool
    {
        if (stripos($html, '_token') === false) {
            // Fast path — no chance of a Laravel CSRF input.
            // (We check stripos on _token first because a meta csrf-token
            // is far less common than a hidden _token input.)
            return (bool) preg_match('#<meta[^>]*name=["\']csrf-token["\']#i', $html);
        }

        return (bool) preg_match('#<input[^>]*name=["\']_token["\']#i', $html)
            || (bool) preg_match('#<meta[^>]*name=["\']csrf-token["\']#i', $html);
    }

    /**
     * True when the request belongs to a logged-in user. We avoid resolving
     * Auth from the container (might not be bound yet on every request);
     * instead we sniff the session for the canonical Laravel auth key. This
     * is a heuristic — a couple of false positives on session-using-but-
     * unauthenticated routes is acceptable; we only miss out on caching them
     * which is a perf trade-off, not a correctness one.
     */
    private function requestHasAuthSession(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        try {
            foreach ($request->session()->all() as $key => $value) {
                if (str_starts_with((string) $key, 'login_web_') && ! empty($value)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Session not started yet, etc.
        }

        return false;
    }

    private function isAsyncMode(): bool
    {
        // Managed (gateway) clients never run a local queue worker — all
        // translation happens on our servers via inline HTTP — so a background
        // page-job would sit in the queue forever, leaving the page in the
        // source language and the "Translating…" banner spinning. Always inline.
        if (TranslatorFactory::detectMode() === 'gateway') {
            return false;
        }

        // Async is an EXPLICIT opt-in. We no longer infer it from
        // queue.default !== 'sync': Laravel 11/12 default QUEUE_CONNECTION to
        // `database`, so auto-async silently dispatched a TranslatePageJob on
        // worker-less hosts that never processed it — the page stayed in the
        // source language and the JS banner hung for the full poll window.
        // Hosts that genuinely run `php artisan queue:work --queue=lingua`
        // re-enable async with LINGUA_ASYNC=true.
        return (bool) config('lingua.async', false)
            && (string) config('queue.default', 'sync') !== 'sync';
    }

    private function pageKey(Request $request, string $lang): string
    {
        // rtrim the trailing slash so the warm job (which builds absolute URLs
        // that may end in '/') and this serve-time key always agree — otherwise
        // a warmed root page ("…/") is never served to a visitor ("…").
        return 'lingua_page_'.md5(rtrim($request->url(), '/').$lang);
    }

    /**
     * Set a Cache-Control header on the HTML we serve so browsers always
     * revalidate and never display a stale cached page — visitors never have to
     * clear their browser cache by hand. Configurable via
     * `lingua.browser_cache_control`; set it to an empty string to leave the
     * host's own caching headers untouched.
     */
    private function applyBrowserCacheHeader(SymfonyResponse $response): void
    {
        $cc = config('lingua.browser_cache_control', 'no-cache, must-revalidate');
        if (is_string($cc) && $cc !== '') {
            $response->headers->set('Cache-Control', $cc);
        }
    }

    private function getPageCache(Request $request, string $lang): ?string
    {
        // A misconfigured/missing cache store must degrade to "no cache", never 500.
        try {
            return Cache::driver(config('lingua.cache_driver', 'file'))
                ->get($this->pageKey($request, $lang));
        } catch (\Throwable) {
            return null;
        }
    }

    private function setPageCache(Request $request, string $lang, string $html): void
    {
        try {
            $driver = config('lingua.cache_driver', 'file');
            $key = $this->pageKey($request, $lang);
            $ttl = config('lingua.cache_ttl', 86400) * 60;
            $cache = Cache::driver($driver);

            if ($cache->add($key, $html, $ttl)) {
                TranslationCache::bumpStat(TranslationCache::STATS_PAGES_TOTAL);
            } else {
                $cache->put($key, $html, $ttl);
            }
        } catch (\Throwable) {
            // Caching is best-effort; a cache failure must never break the host.
        }
    }

    private function injectAssets(string $html): string
    {
        $config = json_encode([
            'source_language' => config('lingua.source_language', 'en'),
            'supported_languages' => $this->selectorLanguages(),
            'selector_position' => config('lingua.selector_position', 'top-right'),
            'selector_style' => config('lingua.selector_style', 'flags'),
            'auto_detect' => config('lingua.auto_detect', true),
            'translate_input_url' => url('/lingua/translate-input'),
            'translate_dom_url' => url('/lingua/translate-dom'),
            'excluded_fields' => config('lingua.excluded_fields', []),
            'skip_field_patterns' => config('lingua.translate_field_patterns.skip', []),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

        $metaTag = '<meta name="lingua-config" content=\''.$config.'\'>';
        $scriptTag = '<script src="'.$this->linguaScriptSrc().'" defer></script>';

        return $this->injectIntoHead($html, $metaTag."\n".$scriptTag);
    }

    /**
     * The languages the selector should offer. In gateway mode the license
     * entitles the client to a specific set of target languages, so the
     * selector is narrowed to the source language + those allowed targets.
     * When there is no restriction (standalone, or an older gateway that does
     * not advertise the entitlement) every supported language is offered.
     *
     * @return array<string,array<string,string>>
     */
    private function selectorLanguages(): array
    {
        $all = (array) config('lingua.supported_languages', []);

        $allowed = $this->allowedTargetLanguages();
        if ($allowed === null) {
            return $all;
        }

        $source = strtolower((string) config('lingua.source_language', 'en'));
        $keep = array_merge([$source], $allowed);

        $filtered = array_filter(
            $all,
            fn ($code) => in_array(strtolower((string) $code), $keep, true),
            ARRAY_FILTER_USE_KEY
        );

        // Never hand the selector an empty/sourceless list — fall back to all.
        return $filtered === [] ? $all : $filtered;
    }

    /**
     * Target languages this install's license allows, or null when there is no
     * restriction. Fully fail-safe: any resolution/verify error → null so the
     * selector degrades to "all languages" rather than disappearing.
     *
     * @return array<int,string>|null lowercase codes, or null = unrestricted
     */
    private function allowedTargetLanguages(): ?array
    {
        try {
            $translator = app(TranslatorInterface::class);
            if (method_exists($translator, 'getAllowedLanguages')) {
                $allowed = $translator->getAllowedLanguages();
                if (is_array($allowed) && $allowed !== []) {
                    return array_map(fn ($c) => strtolower((string) $c), $allowed);
                }
            }
        } catch (\Throwable) {
            // fail-safe — no restriction
        }

        return null;
    }

    /**
     * Resolve the lingua.js URL to inject.
     *
     * GATEWAY mode: load it CENTRALLY from the gateway, cache-busted by the JS
     * version the gateway advertises — so a JS improvement reaches every gateway
     * client automatically, with no composer update on their side.
     *
     * STANDALONE: the host's own published asset, addressed via APP_URL so it is
     * NEVER emitted as http://localhost when the page is rendered in a CLI/warm
     * context (which would 404/CORS-fail and break the selector on cached pages).
     */
    private function linguaScriptSrc(): string
    {
        $version = GatewayClient::PACKAGE_VERSION;

        if (config('lingua.gateway.serve_assets', true)) {
            $gatewayUrl = rtrim((string) config('lingua.gateway.url', ''), '/');
            $licenseKey = (string) config('lingua.gateway.license_key', '');
            if ($gatewayUrl !== '' && $licenseKey !== '') {
                $jsVersion = GatewayClient::cachedJsVersion() ?: $version;

                return $gatewayUrl.'/lingua.js?v='.$jsVersion;
            }
        }

        // ROOT-RELATIVE on purpose: the browser resolves it against the real
        // page origin, so it is NEVER emitted as http://localhost — no matter
        // what APP_URL is or whether the page was rendered in a CLI/warm context.
        return '/vendor/lingualayer/lingua.js?v='.$version;
    }

    private function injectTranslatingMeta(string $html, string $pageHash): string
    {
        $meta = '<meta name="lingua-translating" content="'.$pageHash.'">';

        return $this->injectIntoHead($html, $meta);
    }

    /**
     * Insert markup into the document head. Falls back gracefully when the
     * response has no </head> (partial views, error pages, malformed HTML)
     * so the selector UI still reaches the browser on every rendered page.
     */
    private function injectIntoHead(string $html, string $markup): string
    {
        if (preg_match('/<\/head>/i', $html)) {
            return preg_replace('/<\/head>/i', $markup."\n</head>", $html, 1);
        }
        if (preg_match('/<body\b[^>]*>/i', $html)) {
            return preg_replace('/<body\b[^>]*>/i', '$0'."\n".$markup, $html, 1);
        }

        // Last resort: prepend — better to ship the selector than drop it.
        return $markup."\n".$html;
    }

    private function isExcludedRoute(Request $request): bool
    {
        $patterns = config('lingua.excluded_routes', []);
        if (empty($patterns)) {
            return false;
        }

        return $request->is(...$patterns);
    }

    /**
     * Heuristic: only touch responses that actually look like a rendered page.
     * Prevents corruption of HTML fragments returned by controllers that hand
     * back partial markup (e.g. Livewire/HTMX responses served as text/html).
     */
    private function looksLikeFullHtml(string $html): bool
    {
        return (bool) preg_match('/<html\b|<body\b|<head\b/i', $html);
    }

    private function detectLanguage(Request $request): string
    {
        // lingua_lang is exempted from EncryptCookies so it survives as a plain
        // cookie. Prefer $_COOKIE (real requests) but fall back to the request
        // bag for test environments where the superglobal isn't populated.
        $lang = $_COOKIE['lingua_lang']
            ?? $request->cookie('lingua_lang')
            ?? $request->header('X-Lingua-Lang')
            ?? '';

        $supported = array_keys(config('lingua.supported_languages', []));

        if (in_array($lang, $supported, true)) {
            return $lang;
        }

        return config('lingua.source_language', 'en');
    }
}

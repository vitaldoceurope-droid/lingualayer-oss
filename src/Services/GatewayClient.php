<?php

namespace LinguaLayer\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Contracts\TranslatorInterface;

/**
 * Talks to a remote LinguaLayer Gateway over HTTPS. Drops in for the local
 * GeminiTranslator when LINGUA_LICENSE_KEY is set — the host never sees an
 * API key for any LLM.
 *
 * Resilience strategy:
 *   - 2 retries on connection failure with 500ms / 1s backoff.
 *   - 401/403/429 → log + return null (the caller surfaces the source text).
 *   - Network errors during a known-good license window (72h grace by
 *     default) keep the package functional — the locally persisted DB still
 *     answers any cached translation.
 */
class GatewayClient implements TranslatorInterface
{
    public const PACKAGE_VERSION = '1.6.2';

    private const VERIFY_CACHE_KEY = 'lingua_gateway_license_valid';

    private const USAGE_CACHE_KEY = 'lingua_gateway_usage';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $licenseKey,
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
    ) {}

    public function getName(): string
    {
        return 'gateway';
    }

    public function isConfigured(): bool
    {
        return $this->licenseKey !== '' && $this->baseUrl !== '';
    }

    public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $body = array_filter([
            'text' => $text,
            'source' => $source ?? config('lingua.source_language', 'en'),
            'target' => $target,
            'context' => $context,
            'client_domain' => $this->resolveDomain() ?: null,
        ], fn ($v) => $v !== null);

        $resp = $this->retryingPost('/api/v1/translate', $body);
        if ($resp === null) {
            return null;
        }

        $cached = $resp['cached'] ?? false;
        Log::channel('single')->info('[LinguaLayer][gateway] translate ok', [
            'cached' => $cached,
            'model' => $resp['model'] ?? null,
            'ms' => $resp['response_time_ms'] ?? null,
        ]);

        return $resp['translated'] ?? null;
    }

    /** Max items per Gateway batch request. Smaller chunks → more HTTP calls
     *  but each one stays well under PHP's max_execution_time on the server. */
    private const MAX_BATCH_SIZE = 100;

    public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
    {
        if (! $this->isConfigured() || empty($texts)) {
            return null;
        }

        // Pages with thousands of unique fragments must be chunked or the
        // Gateway rejects the request with HTTP 422 (texts.max validator).
        // We chunk transparently here and concatenate the results.
        //
        // FIX 2026-04-27: previously a single failed chunk caused us to drop
        // ALL the translations from the batch (return null), which made the
        // host store skip persisting fragments that DID translate fine.
        // Result: nav strings like "Inicio" / "Plataforma" stayed untranslated
        // forever once a single Gateway timeout hit during a warm sweep.
        // Now we keep the successful chunks and put null in the slots from
        // the failed chunk only. The caller (HtmlTranslator) already treats
        // null entries as "leave source as-is", so partial failures degrade
        // gracefully without losing the good work already done.
        $values = array_values($texts);
        $chunks = array_chunk($values, self::MAX_BATCH_SIZE);
        $allTranslations = [];
        $hadAnyFailure = false;
        $hadAnySuccess = false;

        foreach ($chunks as $chunkIdx => $chunk) {
            $body = array_filter([
                'texts' => $chunk,
                'source' => $source ?? config('lingua.source_language', 'en'),
                'target' => $target,
                'context' => $context,
                'client_domain' => $this->resolveDomain() ?: null,
            ], fn ($v) => $v !== null);

            $resp = $this->retryingPost('/api/v1/translate-batch', $body);
            if ($resp === null) {
                // Chunk failed after retries — fill its slots with null and
                // continue with the remaining chunks. Do NOT abort the whole
                // batch.
                $hadAnyFailure = true;
                $allTranslations = array_merge($allTranslations, array_fill(0, count($chunk), null));
                Log::channel('single')->warning('[LinguaLayer][gateway] batch chunk dropped', [
                    'chunk_index' => $chunkIdx,
                    'chunk_size' => count($chunk),
                    'preserved' => count($allTranslations),
                ]);

                continue;
            }

            $translations = $resp['translations'] ?? null;
            if (! is_array($translations) || count($translations) !== count($chunk)) {
                $hadAnyFailure = true;
                $allTranslations = array_merge($allTranslations, array_fill(0, count($chunk), null));
                Log::channel('single')->warning('[LinguaLayer][gateway] batch size mismatch', [
                    'expected' => count($chunk),
                    'got' => is_array($translations) ? count($translations) : 'non-array',
                ]);

                continue;
            }

            $allTranslations = array_merge($allTranslations, $translations);
            $hadAnySuccess = true;

            Log::channel('single')->info('[LinguaLayer][gateway] batch chunk ok', [
                'chunk_size' => count($chunk),
                'cached_count' => $resp['cached_count'] ?? null,
                'api_count' => $resp['api_count'] ?? null,
                'ms' => $resp['response_time_ms'] ?? null,
            ]);
        }

        // If every chunk failed, behave like the original "all-or-nothing"
        // contract so callers that expect null on total failure still work.
        if (! $hadAnySuccess) {
            return null;
        }

        if ($hadAnyFailure) {
            Log::channel('single')->info('[LinguaLayer][gateway] partial batch — returning successes', [
                'translated' => count(array_filter($allTranslations, fn ($v) => $v !== null)),
                'dropped' => count(array_filter($allTranslations, fn ($v) => $v === null)),
            ]);
        }

        // Re-key by original positions so callers can use array_combine logic.
        $out = [];
        $i = 0;
        foreach ($texts as $key => $_) {
            $out[$key] = $allTranslations[$i++] ?? null;
        }

        return $out;
    }

    /**
     * Verify the license against the Gateway. Caches the boolean for 24h,
     * with an additional grace window: if the Gateway is unreachable but a
     * recent positive verification exists, we keep returning true so the
     * package stays functional during transient outages.
     */
    public function verifyLicense(?string $domain = null): bool
    {
        $cached = Cache::get(self::VERIFY_CACHE_KEY);
        if ($cached !== null && ($cached['expires_at'] ?? 0) > time()) {
            return (bool) $cached['valid'];
        }

        try {
            $resp = $this->client()
                ->post($this->baseUrl.'/api/v1/license/verify', array_filter([
                    'license_key' => $this->licenseKey,
                    'domain' => $domain,
                ], fn ($v) => $v !== null));

            if (! $resp->successful()) {
                return $this->graceVerdict($cached);
            }

            $valid = (bool) ($resp->json('valid') ?? false);

            // Capture the plan's language entitlement so the package can narrow
            // its selector to permitted languages. Absent on older gateways →
            // null (no restriction), keeping this backward compatible.
            $allowed = $resp->json('allowed_languages');
            $allowed = is_array($allowed) && $allowed !== [] ? array_values($allowed) : null;

            // The client JS version the gateway currently serves. Lets the host
            // load lingua.js centrally from the gateway and pick up JS updates
            // automatically (cache-busted by this version) without a composer
            // update. Absent on older gateways → null (host falls back to its
            // bundled asset), keeping this backward compatible.
            $jsVersion = $resp->json('js_version');
            $jsVersion = is_string($jsVersion) && $jsVersion !== '' ? $jsVersion : null;

            Cache::put(self::VERIFY_CACHE_KEY, [
                'valid' => $valid,
                'expires_at' => time() + 86400, // 24h
                'last_ok_at' => $valid ? time() : ($cached['last_ok_at'] ?? 0),
                'allowed_languages' => $allowed,
                'max_languages' => $resp->json('max_languages'),
                'js_version' => $jsVersion,
            ], 86400 * 30);

            return $valid;
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[LinguaLayer][gateway] verifyLicense error', [
                'error' => $e->getMessage(),
            ]);

            return $this->graceVerdict($cached);
        }
    }

    /**
     * Inside the grace window, return the last known-good verdict. Outside,
     * deny — if the gateway has been unreachable for too long we should not
     * keep accepting traffic indefinitely.
     */
    private function graceVerdict(?array $cached): bool
    {
        if ($cached === null) {
            return false;
        }
        $graceHours = (int) config('lingua.gateway.fallback_grace_hours', 72);
        $deadline = ($cached['last_ok_at'] ?? 0) + ($graceHours * 3600);

        return time() < $deadline && (bool) ($cached['valid'] ?? false);
    }

    /**
     * The target languages this license is entitled to translate into, as
     * advertised by the Gateway's /license/verify response. Returns null when
     * there is no restriction (unlimited plan, an older gateway that doesn't
     * send the field, or the verdict is unavailable) — callers must treat null
     * as "all languages allowed" so the selector is never wrongly emptied.
     *
     * Reads the cached verify verdict; warms it with a single verify call
     * (itself cached 24h) when cold. Fully fail-safe: any error → null.
     *
     * @return array<int,string>|null lowercase ISO codes, or null = unrestricted
     */
    public function getAllowedLanguages(): ?array
    {
        try {
            $cached = Cache::get(self::VERIFY_CACHE_KEY);
            if (! is_array($cached) || ($cached['expires_at'] ?? 0) <= time()) {
                $this->verifyLicense(request()?->getHost());
                $cached = Cache::get(self::VERIFY_CACHE_KEY);
            }

            $langs = is_array($cached) ? ($cached['allowed_languages'] ?? null) : null;
            if (! is_array($langs) || $langs === []) {
                return null;
            }

            return array_values(array_unique(array_map('strtolower', $langs)));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The client-JS version the gateway currently serves, from the cached verify
     * verdict. Used to cache-bust the centrally-hosted lingua.js so a JS update
     * on the gateway reaches every client automatically. Null = unknown (older
     * gateway / cold cache) → host falls back to its bundled asset version.
     */
    public static function cachedJsVersion(): ?string
    {
        try {
            $cached = Cache::get(self::VERIFY_CACHE_KEY);
            $v = is_array($cached) ? ($cached['js_version'] ?? null) : null;

            return is_string($v) && $v !== '' ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the client's plan + remaining quota + network-effect breakdown.
     * Cached 5 min so the dashboard polling doesn't hammer the Gateway.
     */
    public function getUsage(): ?array
    {
        $hit = Cache::get(self::USAGE_CACHE_KEY);
        if ($hit !== null) {
            return $hit;
        }

        try {
            $resp = $this->client()->get($this->baseUrl.'/api/v1/usage');
            if (! $resp->successful()) {
                return null;
            }
            $data = $resp->json();
            Cache::put(self::USAGE_CACHE_KEY, $data, 300);

            return $data;
        } catch (\Throwable $e) {
            Log::channel('single')->debug('[LinguaLayer][gateway] usage fetch failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string,mixed>|null decoded JSON payload, or null on any
     *                                  non-2xx / connection failure
     */
    private function retryingPost(string $path, array $body): ?array
    {
        $maxAttempts = 3;
        $backoffsMicro = [500_000, 1_000_000];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $resp = $this->client()->post($this->baseUrl.$path, $body);

                if ($resp->successful()) {
                    return $resp->json();
                }

                $status = $resp->status();
                $level = match (true) {
                    $status === 401 => 'warning',
                    $status === 403 => 'warning',
                    $status === 429 => 'warning',
                    $status >= 500 => 'error',
                    default => 'warning',
                };
                Log::channel('single')->{$level}('[LinguaLayer][gateway] non-2xx', [
                    'path' => $path,
                    'status' => $status,
                    'body' => substr((string) $resp->body(), 0, 200),
                ]);

                // 4xx is terminal — retrying won't help
                if ($status < 500) {
                    return null;
                }
            } catch (\Throwable $e) {
                Log::channel('single')->warning('[LinguaLayer][gateway] connection error', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffsMicro[$attempt - 1] ?? 1_000_000);
            }
        }

        return null;
    }

    /**
     * The host this install serves, for the gateway's domain-binding check.
     * Uses the live request host when there is one (web traffic), else falls
     * back to APP_URL — so CLI (lingua:test), queue (warm) and middleware
     * contexts all report the right domain instead of an empty value.
     *
     * Never emits localhost/127.0.0.1: returns '' instead, so a misconfigured
     * APP_URL can never send a bogus localhost domain to the gateway.
     */
    private function resolveDomain(): string
    {
        $host = request()?->getHost();
        if (! $host || $host === 'localhost' || $host === '127.0.0.1') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        }

        return ($host === 'localhost' || $host === '127.0.0.1') ? '' : (string) $host;
    }

    private function client(): PendingRequest
    {
        $http = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-License-Key' => $this->licenseKey,
                'X-Lingua-Version' => self::PACKAGE_VERSION,
                'X-Lingua-Domain' => $this->resolveDomain(),
                'User-Agent' => 'LinguaLayer/'.self::PACKAGE_VERSION,
                // Some tunnel/proxy providers show a browser interstitial on the
                // first request; this header skips it. No effect on direct URLs.
                'ngrok-skip-browser-warning' => '1',
            ]);

        if (! $this->verifySsl) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }
}

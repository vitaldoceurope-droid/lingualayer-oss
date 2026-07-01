# Changelog

All notable changes to LinguaLayer are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.7.0] - 2026-06-29

A safe-by-default + security hardening pass ahead of public distribution. The
defaults now assume nothing about the installing app and never spend its money
or expose a control surface without an explicit choice.

### Security
- **The quality dashboard and its action endpoints now require a secret in every
  environment except `local`.** Previously `LINGUA_QUALITY_SECRET` was enforced
  only in `production`, so on any internet-reachable non-production host
  (staging/UAT/dev) the dashboard **and** its CSRF-exempt state-changing actions
  (`/lingua/quality/action/*`) were open to anyone. With no secret set the
  surface is now reachable only on a genuinely local dev box; everywhere else it
  returns 404. A configured secret is required (and timing-safe compared) in all
  environments.
- **`clear-cache` no longer flushes the host's entire cache store.** The dashboard
  action used `Cache::flush()`, which wiped the **host app's own** cached data —
  including cache-backed sessions, locks and rate-limiters. It now forgets only
  LinguaLayer's own well-known keys; content-addressed per-page/per-fragment
  entries expire by TTL. Use **Scan & translate now** or `php artisan cache:clear`
  for an immediate full rebuild.

### Changed
- **Source language now follows your app's locale by default.** `source_language`
  defaults to `config('app.locale')` (usually `en`) instead of a hard-coded `es`,
  so a fresh install is correct out of the box. `lingua:configure` now asks for it.
- **The autonomous agent + cron auto-warm are now opt-in** (`LINGUA_AGENT_ENABLED`
  defaults to `false`). They were enabled by default, so once a provider key and
  the standard Laravel scheduler cron were present the package crawled and
  translated the whole site every minute, spending the host's LLM budget with no
  explicit opt-in. `lingua:configure` now offers to enable it. (Consistent with
  the async→opt-in change in 1.6.0.)

### Added
- **`.env.example` now documents every configuration key** — 25 previously
  undocumented keys covering persistent storage & cleanup, agent tuning, gateway
  timeout/SSL/grace window, chunk size, browser cache-control and target-language
  narrowing.
- Regression tests covering the cache-flush blast radius and the
  secret-required-outside-local access model.
- **Per-request caps on the public translate endpoints** — a single call to
  `/lingua/translate-input` or `/translate-dom` may carry at most
  `LINGUA_MAX_FIELDS` (200) fields and `LINGUA_MAX_BYTES` (100 KB) of text;
  oversized requests are served back unchanged (never billed), preventing one
  request from fanning out into a huge batch.

### Migration notes (1.6.x → 1.7.0)
- If you relied on the agent translating your site automatically, set
  `LINGUA_AGENT_ENABLED=true`.
- If your app's content language differs from `config('app.locale')`, set
  `LINGUA_SOURCE_LANG` explicitly (it previously defaulted to `es`).
- On any non-`local` environment where you use the quality dashboard, set
  `LINGUA_QUALITY_SECRET` — otherwise the dashboard now returns 404 there.

## [1.6.2] - 2026-06-29

### Fixed
- **A fragment that failed translation could stick untranslated in the client
  cache.** When the gateway returned a fragment unchanged — which happens both
  for a genuine no-op AND when a fragment failed and was echoed back as its
  source — `lingua.js` cached it as the "translation", freezing it in the
  source language for the cache's TTL (e.g. a Spanish description staying
  Spanish on a French page even after the gateway was fixed). The client now
  caches **only genuine translations** (where the result differs from the
  source); an echoed-back fragment is left uncached so a later sweep/visit
  retries it. The DOM-cache version was bumped so every client drops any such
  poisoned entries on next load.

## [1.6.1] - 2026-06-29

### Fixed
- **One failed chunk no longer leaves a whole page untranslated.** When a batch
  was split into chunks and any chunk failed all its retries (a transient
  rate-limit, or the model drifting on the ⟦LL:N⟧ delimiters — likelier the
  bigger the chunk), `AbstractLlmTranslator::translateBatch` returned `null` for
  the **entire** batch, so a content-heavy page (or a SPA's DOM fragments) could
  stay entirely in the source language. The engine now **recovers by splitting
  the failed chunk into halves** (recursively, down to single items) before
  giving up — most “failures” are delimiter drift on a big chunk and translate
  fine once split. The atomic guarantee is preserved: if an item still cannot be
  translated even on its own, the whole batch fails so the page is served in the
  source language rather than half-translated.

## [1.6.0] - 2026-06-29

### Changed
- **Async page-translation is now an explicit opt-in (`LINGUA_ASYNC=true`).**
  Previously LinguaLayer switched to async mode automatically whenever the queue
  driver was non-sync. Laravel 11/12 default `QUEUE_CONNECTION` to `database`, so
  this silently flipped worker-less installs into async: the background
  `TranslatePageJob` was dispatched but never processed, leaving pages
  untranslated and the "Translating…" banner spinning for ~4 minutes. Async now
  requires `LINGUA_ASYNC=true` **and** a non-sync queue **and** a running worker
  (`php artisan queue:work --queue=lingua`). With it off (the default), every
  page is translated inline (atomic) and page-cached, so repeat visits are
  instant with no worker. **Gateway (managed) installs are always inline.**

### Fixed
- **Gateway clients no longer go async.** In gateway mode there is no local
  worker (translation happens on the managed servers), so `isAsyncMode()` now
  always returns false there — killing the lingering "Translating…" banner and
  the never-server-translated page on managed clients.
- **Worker-less hosts no longer accumulate dead jobs.** The opportunistic
  pre-warm tick in `terminate()` now fires only when async is opted in (i.e. a
  worker exists), instead of whenever the queue was non-sync — which had been
  enqueueing a `WarmAllPagesJob` every ~30 min that no worker ever drained,
  slowly bloating the host's `jobs` table.
- **Error pages are never frozen by the cache.** Responses with a non-200 status
  (404/410/500/503) are no longer translated, queued, or page-cached — a
  transient error could otherwise be re-served as a 200 for the full cache TTL.
- **Mobile: a storage exception no longer disables translation.** `lingua.js`
  now routes every `localStorage`/`sessionStorage` access through safe wrappers
  and isolates each `init()` subsystem in its own try/catch, so a `SecurityError`
  on iOS Safari ("Block All Cookies") or in in-app webviews can no longer abort
  the whole script (which previously left the page with no selector and no
  translation at all).
- **"Translating…" banner gives up gracefully.** It now treats an `unknown`
  status as terminal and stops waiting when no worker ever picks the job up,
  instead of spinning the full 120×2s.

### Added
- **Cross-session instant repeat visits.** The client-side DOM translation cache
  moved from `sessionStorage` (wiped on tab close) to `localStorage` with a
  version stamp + 7-day TTL, keyed by source text. Repeat visits — a new tab,
  the next day, the phone — re-apply translations instantly with no gateway
  round-trip. No-op fragments (translation equals source) are now cached too.
- **Mobile-safe language selector.** Safe-area insets (iOS notch/home
  indicator), ≥40px tap targets on touch devices, and the `-webkit-`
  backdrop-filter prefix for older iOS Safari.
- **`LINGUA_GATEWAY_SERVE_ASSETS`** documented in `config/lingua.php` and
  `.env.example` (it was read but undocumented).
- A JS↔PHP version-parity test guarding `window.__linguaVersion` against
  `GatewayClient::PACKAGE_VERSION`.

## [1.5.4] - 2026-06-24

### Fixed
- **Dynamic / SPA content was left untranslated.** The client-side DOM translator
  (`lingua.js`) had three gaps on single-page apps: (1) nodes were marked "seen" at
  collection time, so any node whose batch hit a transient gateway failure was
  abandoned forever; (2) it only re-scanned on `hashchange`, missing History-API
  (pushState) SPA navigation; (3) it relied solely on the MutationObserver with no
  safety sweep. Now nodes are marked done **only on successful translation** (failed
  ones stay retryable), large batches are chunked (≤80/request) with up to 5 backoff
  retries, and the page is re-swept periodically after load, on History-API navigation,
  and on scroll/click (lazy-loaded lists, tabs, modals). SPA pages now translate fully.

## [1.5.3] - 2026-06-24

### Fixed
- **Language selector showed every supported language instead of the ones the
  license allows.** In gateway mode the injected `lingua-config` now narrows
  `supported_languages` to the source language plus the license's entitled target
  languages (from the gateway's `allowed_languages`), so a 2-language plan shows
  2 languages, not 40. Standalone installs and unrestricted/older gateways still
  offer every supported language. Fully fail-safe: any error → all languages.

## [1.5.2] - 2026-06-24

### Fixed
- **Domain-bound gateway licenses failed for server-side and CLI translation.**
  `GatewayClient` now resolves the client domain from the live request host *or*
  `APP_URL` (so `lingua:test`, queue warms and middleware-side translation report the
  correct domain even when there is no HTTP request) and sends it both as the
  `X-Lingua-Domain` header and a `client_domain` body field. Previously these
  contexts sent no domain, so a license bound to a domain was rejected with
  `domain_mismatch`. Never emits localhost.

## [1.5.1] - 2026-06-24

### Added
- **40 supported languages out of the box** (was 6), each with its native name and
  flag, plus a **searchable dropdown language selector** in `lingua.js` that scales
  past a handful of languages (the compact flag row is kept for few languages).
- **Worker-less autonomous pre-warm.** `lingua:warm` is registered on the scheduler to
  run inline every minute, so a **single host cron line** (`php artisan schedule:run`)
  keeps pages warm and auto-translates a newly-enabled language within ~a minute — with
  **no queue worker**. New config `agent.auto_warm` (`LINGUA_AGENT_AUTO_WARM_CRON`) and
  `agent.warm_max_seconds`; new `lingua:warm --max-seconds`.

### Fixed
- **Never-500 hardening.** `HtmlTranslator::translate` and the middleware page-cache
  read/write are now fail-safe: any error (malformed HTML, missing memory table, cache or
  LLM failure) degrades to serving the original page instead of a 500.
- **Page-cache key normalization.** A trailing-slash mismatch meant a warmed root page was
  never served to visitors; warm and serve now derive the same key (`rtrim`).
- **Warm rendered `localhost` links.** The `lingua:warm` command built its internal request
  without the host's `APP_URL`, so cached/translated pages emitted `localhost` URLs; it now
  renders against `config('app.url')`.
- **Scheduler reliability.** Removed `onOneServer()` and `--detect-new` from the auto-warm
  (they mis-skipped with the default file cache and across many languages).
- Faster cold translation via a larger, configurable `translation.chunk_size`.

### Security / packaging
- Purged a leaked example license key from shipped source and added `.gitattributes`
  `export-ignore` rules so only the runtime package ships via `composer require` (no
  `dist/`, `tests/`, or internal artifacts). Added composer.json `keywords`/`homepage`/
  `support`, and documented the scheduler cron line in the README, `.env.example`, and
  `lingua:install` output.

## [1.5.0] - 2026-06-23

### Added
- **License-driven language entitlement (gateway mode).** The package reads the
  languages a license is entitled to from the `/license/verify` response
  (`allowed_languages` / `max_languages`) and narrows the language selector to
  them at boot. Composes after the host's `LINGUA_TARGET_LANGUAGES` filter, only
  ever narrows, fail-open, and a no-op in standalone mode. New
  `GatewayClient::getAllowedLanguages()`.
- **Currency-amount preservation.** `PlaceholderProtector` now masks amounts
  adjacent to a currency symbol (`1.299,50 €`, `$1,299.50`) so prices round-trip
  verbatim instead of being re-localised by the model, while bare counts, years
  and version strings stay translatable.
- **Opportunistic cron-less pre-warm tick.** A terminable-middleware tick (after
  the response is sent, throttled once per `agent.tick_interval_minutes`)
  dispatches a bounded warm so pages pre-warm during normal traffic — removing
  the cron dependency for warming on hosts that run a queue worker. New config:
  `agent.tick_enabled`, `agent.tick_interval_minutes`.

### Fixed
- **Fail-safe binding.** An installed-but-unconfigured package no longer throws
  during container resolution (which 500'd every request via middleware
  construction); the `TranslatorInterface` binding now falls back to a no-op
  `NullTranslator`, so the host serves original text untouched.
- **`agent.max_pages_per_run` is now enforced** in `WarmAllPagesJob` (was
  documented but ignored), with a log line when a run is capped.

## [1.4.0] - 2026-06-22

### Added
- **Pluggable LLM provider.** `LINGUA_PROVIDER` selects the standalone backend:
  `gemini` (default) or `openai`. The `openai` driver speaks the OpenAI
  `/chat/completions` schema, so it also drives Groq, Together, Mistral, or a
  self-hosted model (Ollama, vLLM, LocalAI) via `LINGUA_OPENAI_BASE_URL`.
- **`AbstractLlmTranslator`** — provider-neutral engine (cache, batching,
  delimiter protocol, retries, few-shot, prompt building); a new backend only
  implements `callModel()`.
- **Placeholder & brand preservation.** Laravel `:placeholders`, `{curly}` vars,
  `%s`/`%d` printf tokens, `@mentions`, `#tags`, inline URLs/emails and
  configured brand terms are masked before translation and restored verbatim,
  with a source-text fallback if a token is dropped.
- **Configurable translation register.** `LINGUA_DOMAIN`, `LINGUA_FORMALITY`,
  brand terms and a per-language glossary.
- **`lingua:memory` command** — stats, JSONL export and import of the translation
  memory, so a corpus can seed a fresh install.
- **Configurable rate limits** via `lingua.throttle.input` / `.dom`.
- `LICENSE` file, `CHANGELOG.md`, Pint and PHPStan/Larastan configuration.

### Changed
- The standalone translator's system prompt is now domain-agnostic (`generic` by
  default) instead of hardcoded to the medical domain. **Existing medical
  installs should set `LINGUA_DOMAIN=medical`** to keep the previous behaviour.
- `/lingua/translate-input` and `/lingua/translate-dom` now resolve the
  `TranslatorInterface` instead of the concrete Gemini driver.

### Fixed
- Form-input translation now respects gateway mode (previously it always used the
  concrete Gemini driver, breaking input translation for gateway-only installs).

## [1.3.1]
- Fix `interceptForms` for SPAs (React/Vue): do not intercept forms without an
  HTML `action`, preventing an accidental reload to the dashboard.

## [1.3.0]
- Plan C: translate all user-facing content (placeholders, title, alt, aria) plus
  the initial DOM and dynamic mutations, with sessionStorage cache and retries.

## [1.2.0]
- Deploy bundling, gateway compatibility headers, and throttle tuning for
  dashboard bursts.

# LinguaLayer

Bidirectional AI translation middleware for Laravel. Drop it into any existing app and every page becomes multilingual — **without changing a single controller, view, or database column**.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11%2B-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## Two modes

LinguaLayer ships with **two operating modes**. Pick one based on whether you want to manage your own LLM API keys.

| | **Standalone** | **Gateway** |
|---|---|---|
| Who pays the LLM | You, directly to Google | LinguaLayer (subscription) |
| Setup | Set `LINGUA_GEMINI_KEY` | Set `LINGUA_LICENSE_KEY` |
| Monthly fee | None | Subscription tier |
| Cache | Per-host (your DB) | Per-host **+ global network cache** |
| Best for | Personal projects, small apps | Production, multi-app, network-effect savings |

Pick interactively with `php artisan lingua:configure`.

---

## Network effect (Gateway mode only)

LinguaLayer's Gateway has a unique billing model: **the more clients use it, the cheaper translations get for everyone**.

Imagine you want to translate "Bienvenido" into French. Three scenarios:

- **You're the first client to ever request it:** you pay 1 word.
- **You're client #100 to request it (cached from someone else's translation):** you pay **0 words** — _network effect free_.
- **You request it 5 times yourself:** you pay `1 + (4 × 0.5) = 3 words` instead of 5 — _own-repetition discount_.

Every fresh translation you contribute becomes free for the next clients who need it. Every cached translation those clients seeded is free for you.

Read the breakdown in real time: `php artisan lingua:test` shows mode + savings, and the dashboard at `/lingua/quality` displays a Network Effect Savings panel.

---

## How it works

```
User requests /patients in French
        ↓
TranslateResponse middleware intercepts the HTML response
        ↓
Extracts text from h1–h6, p, button, td, li, a, label…
        ↓
Sends batch to Gemini 2.5-flash — atomic: all chunks must succeed
        ↓
Stores complete translated HTML in full-page cache
        ↓
Next user requesting same URL + language → instant cache hit
```

For form submissions, `lingua.js` intercepts the submit event, POSTs the field values to `/lingua/translate-input`, swaps them with the source-language equivalents, then lets the form submit normally. Identity fields (`firstname`, `address`, `company`…) are never touched.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP         | 8.2+    |
| Laravel     | 11 or 12 |
| Gemini API key | Free tier works |

---

## Quick start

```bash
composer require lingualayer/lingualayer
php artisan lingua:install
php artisan lingua:configure   # interactive: pick standalone or gateway
php artisan lingua:test        # verify
```

That's it. Visit any page of your app and you'll see the language selector in the top-right corner.

### Autonomous agent — one cron line (opt-in)

LinguaLayer can **pre-translate ("warm") your pages automatically** so visitors never wait, and auto-translate any newly-enabled language within ~a minute — with **no queue worker**. Because it spends your LLM budget on a schedule, **it is off by default** — enable it explicitly:

```env
LINGUA_AGENT_ENABLED=true
```

Then give it Laravel's standard scheduler by adding this **single line** to your server's crontab:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This is the exact line [Laravel documents for task scheduling](https://laravel.com/docs/scheduling#running-the-scheduler) — if you already have it, you're done. **Even with the agent off, translation still works** (pages are translated live on first visit); the agent just makes them instant and keeps your translations fresh. `php artisan lingua:configure` offers to enable it for you.

### Path A — Standalone (your own Gemini key)

```env
LINGUA_MODE=standalone
LINGUA_GEMINI_KEY=your-gemini-api-key
# Source language defaults to your app locale (config/app.php). Set this only
# when your content is written in a different language:
# LINGUA_SOURCE_LANG=en
```

You pay Google directly per token. No subscription.

### Path B — Gateway (managed)

```env
LINGUA_MODE=gateway
LINGUA_LICENSE_KEY=LL-XXXX-XXXX-XXXX-XXXX
LINGUA_GATEWAY_URL=https://api.lingualayer.com
# Source language defaults to your app locale; override only if needed:
# LINGUA_SOURCE_LANG=en
```

You pay a fixed monthly subscription. Network-effect cache means your effective cost drops as more clients use the system.

### Switching modes

Run `php artisan lingua:configure` again. The wizard rewrites the relevant lines in `.env` without touching anything else.

---

## Configuration

After publishing, edit `config/lingua.php`. The most common options:

```php
'source_language'     => 'en',            // Your app's base language (defaults to config('app.locale'))
'supported_languages' => [                // Languages users can choose
    'es' => ['name' => 'Español',  'flag' => '🇪🇸'],
    'en' => ['name' => 'English',  'flag' => '🇬🇧'],
    'fr' => ['name' => 'Français', 'flag' => '🇫🇷'],
    'ar' => ['name' => 'العربية',  'flag' => '🇸🇦'],
],
'gemini_model'        => 'gemini-2.5-flash',
'cache_ttl'           => 86400,            // Cache in minutes (60 days)
'selector_position'   => 'top-right',      // top-right | top-left | bottom-right | bottom-left
```

Full `.env` reference: see [`.env.example`](.env.example).

### Choosing an AI provider

Standalone mode is not tied to Gemini. Set `LINGUA_PROVIDER`:

```env
# Google Gemini (default)
LINGUA_PROVIDER=gemini
LINGUA_GEMINI_KEY=your-gemini-key

# …or any OpenAI-compatible endpoint
LINGUA_PROVIDER=openai
LINGUA_OPENAI_KEY=sk-...
LINGUA_OPENAI_MODEL=gpt-4o-mini
LINGUA_OPENAI_BASE_URL=https://api.openai.com/v1
```

`openai` speaks the standard `/chat/completions` schema, so it also drives **Groq, Together, Mistral, or a self-hosted model** (Ollama, vLLM, LocalAI) — just point `LINGUA_OPENAI_BASE_URL` at it (a local model needs no API key). All providers share the same caching, batching, retry and preservation logic; adding a new one is a single `callModel()` method.

### Translation quality & faithful placeholders

The engine is built to translate *input and output* without corrupting your app:

- **Placeholders are guaranteed.** Laravel `:placeholders`, `{curly}` vars, `%s`/`%d` printf tokens, `@mentions`, `#tags`, inline URLs and emails are masked before the text reaches the model and restored verbatim afterwards — the LLM literally cannot translate or drop them. If a model mangles one, that fragment falls back to the source instead of shipping a broken variable.
- **Domain & register.** `LINGUA_DOMAIN` (generic, medical, legal, ecommerce, technical, finance, hospitality, or free text) and `LINGUA_FORMALITY` (formal/informal/neutral) tune terminology and tone.
- **Brand terms & glossary.** `LINGUA_BRAND_TERMS="ViataLing,Acme"` keeps names untouched; `lingua.translation.glossary` pins authoritative term translations per language.

---

## Translation memory

Every translation is persisted in `lingua_translations` and resolved **DB → cache → LLM**, so repeat content costs zero API calls. That growing library is a portable asset you own:

```bash
php artisan lingua:memory                  # stats: entries per language, coverage
php artisan lingua:memory export tm.jsonl  # back up / move the memory
php artisan lingua:memory import tm.jsonl  # seed a fresh install with an existing corpus
```

Seeding a brand-new client install from an export gives it instant coverage instead of paying the LLM to re-learn everything.

---

## Features

### Full-page caching
The first visitor to any URL+language combination triggers translation. Every subsequent visitor gets the pre-translated HTML instantly from cache — zero API calls. Cache TTL is configurable (default: 60 days).

### Atomic translations — no partial pages
Every translation request is split into chunks of 40 texts. If any chunk fails, it retries 3 times with exponential backoff (1s → 2s → 4s). If all retries fail, the page is served in the original language rather than half-translated. The user always sees a coherent page.

### Async mode (opt-in)
By default LinguaLayer translates each page **inline** (atomic) on first visit and page-caches the result, so repeat visits are instant **without any worker** — this is the right default for almost everyone.

If you run a dedicated queue worker you can opt into async mode, where the first visit returns immediately in the source language and a background job fills the cache:
1. First visit → served immediately in source language + "Translating…" banner
2. Background job translates + stores in cache
3. JS polls `/lingua/status/{hash}` every 2s → auto-reloads when ready
4. All subsequent visitors → instant cache hit

To enable, set `LINGUA_ASYNC=true`, use a non-sync queue (`QUEUE_CONNECTION=database` or `redis`) **and run a worker**:
```bash
php artisan queue:work --queue=lingua
```

> **Why opt-in?** Laravel 11/12 default `QUEUE_CONNECTION` to `database`. Earlier versions auto-enabled async whenever the driver was non-sync — which silently dispatched background jobs on hosts that had no worker, leaving pages untranslated and the banner spinning. Async is now explicit and never enabled in **gateway mode** (which has no local worker).

### Smart form field detection
Fields whose names contain identity-related substrings (`name`, `firstname`, `lastname`, `address`, `company`, `dni`…) are never translated — the user's actual name should reach the backend unchanged. Configure in `translate_field_patterns.skip`.

### RTL support
The language selector automatically mirrors its position for right-to-left languages (Arabic, Hebrew, Farsi, Urdu). A `top-right` selector becomes `top-left` when the page `dir="rtl"`.

### Translation quality scoring (optional)
Enable with `LINGUA_AUTO_SCORE=true`. After each background translation, a sample of 3–5 texts is sent back to Gemini for quality evaluation (1–10). Translations scoring below 7 are invalidated and re-translated on the next visit. View all scores at `/lingua/quality`.

---

## Artisan commands

```bash
# Verify installation and API connectivity
php artisan lingua:test

# Inspect / export / import the translation memory
php artisan lingua:memory
php artisan lingua:memory export memory.jsonl
php artisan lingua:memory import memory.jsonl

# Publish assets (run after package updates)
php artisan vendor:publish --tag=lingua-assets --force
```

---

## Excluded routes

By default, LinguaLayer skips `api/*` and `lingua/*`. Add more in `config/lingua.php`:

```php
'excluded_routes' => [
    'api/*',
    'admin/*',
    'webhooks/*',
    'lingua/*',
],
```

---

## Excluded form fields

Fields that are never translated regardless of name:

```php
'excluded_fields' => ['password', 'password_confirmation', '_token', 'email', 'phone'],
```

---

## Quality dashboard

Access at `/lingua/quality` (protect it with `LINGUA_QUALITY_SECRET`):

```
/lingua/quality?key=your-secret
```

Shows: average score per language, low-score count, and a full history of scored translations.

---

## Running tests

```bash
composer test
```

Or with coverage:

```bash
./vendor/bin/pest --coverage
```

---

## Security

- The public `/lingua/*` endpoints are CSRF-exempt (so your forms never 419) and
  rate-limited per IP — `LINGUA_THROTTLE_INPUT` (default 200/min) and
  `LINGUA_THROTTLE_DOM` (default 600/min). Tune them in `.env`. Each request is
  also hard-capped at `LINGUA_MAX_FIELDS` (200) fields and `LINGUA_MAX_BYTES`
  (100 KB) — oversized requests are served back unchanged, never billed.
- The demo/preview routes are **auto-disabled when `APP_ENV=production`** — nothing
  to remove by hand.
- Set `LINGUA_QUALITY_SECRET` to protect the quality dashboard (it 404s without it).
- The full-page cache is never shared across sessions: responses carrying a CSRF
  token (or an authenticated session) are never page-cached.
- In production (`APP_ENV=production`), SSL certificate verification on provider/
  gateway API calls is enforced.

---

## License

MIT © [Mohamed Hounaini — ViataLing](mailto:contact@viataling.com)

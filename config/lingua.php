<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source Language
    |--------------------------------------------------------------------------
    | The base language your app's content is written in. LinguaLayer translates
    | FROM this language to the user's choice. It defaults to your app's own
    | locale (config/app.php → 'locale', usually 'en'), so a fresh install is
    | correct out of the box — set LINGUA_SOURCE_LANG only when your content is
    | written in a different language than your configured app locale.
    */
    'source_language' => env('LINGUA_SOURCE_LANG', config('app.locale', 'en')),

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    | Languages available to end users. Format: ISO 639-1 codes.
    */
    'supported_languages' => [
        'es' => ['name' => 'Español',           'flag' => '🇪🇸'],
        'en' => ['name' => 'English',           'flag' => '🇬🇧'],
        'fr' => ['name' => 'Français',          'flag' => '🇫🇷'],
        'de' => ['name' => 'Deutsch',           'flag' => '🇩🇪'],
        'it' => ['name' => 'Italiano',          'flag' => '🇮🇹'],
        'pt' => ['name' => 'Português',         'flag' => '🇵🇹'],
        'nl' => ['name' => 'Nederlands',        'flag' => '🇳🇱'],
        'pl' => ['name' => 'Polski',            'flag' => '🇵🇱'],
        'ru' => ['name' => 'Русский',           'flag' => '🇷🇺'],
        'uk' => ['name' => 'Українська',        'flag' => '🇺🇦'],
        'cs' => ['name' => 'Čeština',           'flag' => '🇨🇿'],
        'sk' => ['name' => 'Slovenčina',        'flag' => '🇸🇰'],
        'ro' => ['name' => 'Română',            'flag' => '🇷🇴'],
        'hu' => ['name' => 'Magyar',            'flag' => '🇭🇺'],
        'el' => ['name' => 'Ελληνικά',          'flag' => '🇬🇷'],
        'bg' => ['name' => 'Български',          'flag' => '🇧🇬'],
        'hr' => ['name' => 'Hrvatski',          'flag' => '🇭🇷'],
        'sr' => ['name' => 'Српски',            'flag' => '🇷🇸'],
        'sv' => ['name' => 'Svenska',           'flag' => '🇸🇪'],
        'no' => ['name' => 'Norsk',             'flag' => '🇳🇴'],
        'da' => ['name' => 'Dansk',             'flag' => '🇩🇰'],
        'fi' => ['name' => 'Suomi',             'flag' => '🇫🇮'],
        'tr' => ['name' => 'Türkçe',            'flag' => '🇹🇷'],
        'ar' => ['name' => 'العربية',           'flag' => '🇸🇦'],
        'he' => ['name' => 'עברית',             'flag' => '🇮🇱'],
        'fa' => ['name' => 'فارسی',             'flag' => '🇮🇷'],
        'ur' => ['name' => 'اردو',              'flag' => '🇵🇰'],
        'hi' => ['name' => 'हिन्दी',              'flag' => '🇮🇳'],
        'bn' => ['name' => 'বাংলা',             'flag' => '🇧🇩'],
        'ta' => ['name' => 'தமிழ்',             'flag' => '🇮🇳'],
        'zh' => ['name' => '中文',               'flag' => '🇨🇳'],
        'ja' => ['name' => '日本語',             'flag' => '🇯🇵'],
        'ko' => ['name' => '한국어',             'flag' => '🇰🇷'],
        'th' => ['name' => 'ไทย',               'flag' => '🇹🇭'],
        'vi' => ['name' => 'Tiếng Việt',        'flag' => '🇻🇳'],
        'id' => ['name' => 'Bahasa Indonesia',  'flag' => '🇮🇩'],
        'ms' => ['name' => 'Bahasa Melayu',     'flag' => '🇲🇾'],
        'fil' => ['name' => 'Filipino',         'flag' => '🇵🇭'],
        'sw' => ['name' => 'Kiswahili',         'flag' => '🇰🇪'],
        'ca' => ['name' => 'Català',            'flag' => '🇦🇩'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operating mode
    |--------------------------------------------------------------------------
    | LinguaLayer can run in two modes. 'auto' (the default) chooses based on
    | the env vars present:
    |   • LINGUA_LICENSE_KEY set → 'gateway'
    |   • only LINGUA_GEMINI_KEY set → 'standalone'
    |   • both set → 'gateway' wins
    |
    | Force a specific mode by setting LINGUA_MODE explicitly.
    */
    'mode' => env('LINGUA_MODE', 'auto'),  // 'standalone' | 'gateway' | 'auto'

    /*
    |--------------------------------------------------------------------------
    | Gateway (managed-service mode)
    |--------------------------------------------------------------------------
    | When using the LinguaLayer Gateway, the host app calls a single HTTP
    | endpoint with a license key — no Gemini key required locally.
    |
    | fallback_grace_hours: how long the cached "license valid" check survives
    | a Gateway outage. During grace the package keeps serving the locally
    | persisted DB; only after grace does it stop translating.
    */
    'gateway' => [
        'url' => env('LINGUA_GATEWAY_URL', 'https://api.lingualayer.com'),
        'license_key' => env('LINGUA_LICENSE_KEY'),
        'timeout' => (int) env('LINGUA_GATEWAY_TIMEOUT', 30),
        'verify_ssl' => (bool) env('LINGUA_GATEWAY_VERIFY_SSL', true),
        'fallback_grace_hours' => (int) env('LINGUA_GRACE_HOURS', 72),

        // In gateway mode lingua.js is loaded centrally from the gateway, so a
        // client JS improvement reaches every client with no composer update.
        // Set false to fall back to the host's own published asset
        // (/vendor/lingualayer/lingua.js) instead.
        'serve_assets' => (bool) env('LINGUA_GATEWAY_SERVE_ASSETS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Standalone LLM provider
    |--------------------------------------------------------------------------
    | In standalone mode (your own key), which backend to use:
    |   'gemini' — Google Gemini (default)
    |   'openai' — any OpenAI-compatible /chat/completions endpoint: OpenAI,
    |              Groq, Together, Mistral, or a self-hosted model via Ollama /
    |              vLLM / LocalAI (just point LINGUA_OPENAI_BASE_URL at it).
    |
    | The provider is irrelevant in gateway mode — the managed service decides.
    */
    'provider' => env('LINGUA_PROVIDER', 'gemini'),  // 'gemini' | 'openai'

    /*
    |--------------------------------------------------------------------------
    | Gemini API (provider = gemini)
    |--------------------------------------------------------------------------
    */
    'gemini_api_key' => env('LINGUA_GEMINI_KEY', env('GEMINI_API_KEY')),
    'gemini_model' => env('LINGUA_GEMINI_MODEL', 'gemini-2.5-flash'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI-compatible API (provider = openai)
    |--------------------------------------------------------------------------
    | Works with OpenAI and any API that speaks the same /chat/completions
    | schema. For a self-hosted model set base_url to your own LLM host
    | (e.g. Ollama: http://<your-ollama-host>:11434/v1) — api_key then optional.
    */
    'openai' => [
        'api_key' => env('LINGUA_OPENAI_KEY', env('OPENAI_API_KEY')),
        'model' => env('LINGUA_OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('LINGUA_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation engine — quality & preservation (bidirectional)
    |--------------------------------------------------------------------------
    | These options shape HOW every translation is produced, in BOTH directions
    | and on EVERY path: full-page HTML output (source→target), dynamic DOM
    | (source→target) and form input (target→source). They make LinguaLayer a
    | faithful translator instead of a naive "throw text at an LLM" call.
    |
    | domain:    Tunes register/terminology. A built-in
    |            (generic|medical|legal|ecommerce|technical|finance|hospitality)
    |            OR any free-text domain (e.g. 'veterinaria'). Default 'generic'
    |            = neutral, natural, faithful — safe for any app.
    |
    | formality: formal | informal | neutral. Controls tú/usted, du/Sie,
    |            vous/tu… in languages that distinguish register.
    |
    | brand_terms: words that must NEVER be translated (product/brand names,
    |            e.g. 'ViataLing'). They are masked before the text reaches the
    |            LLM and restored verbatim — the model cannot alter them.
    |            Also settable via LINGUA_BRAND_TERMS="ViataLing,Acme".
    |
    | glossary:  Authoritative term mappings. Two accepted shapes:
    |              ['Panel' => 'Dashboard']                 // all languages
    |              ['en' => ['Panel' => 'Dashboard'], …]    // per target lang
    |
    | preserve_enabled: master switch for placeholder/brand masking (default on).
    |
    | preserve_patterns: extra regexes whose matches survive translation
    |            untouched, on top of the built-ins (Laravel :placeholders,
    |            {curly} vars, %s/%d printf tokens, @mentions, #tags, inline
    |            URLs and emails). Example: '/\bSKU-\d+\b/'.
    */
    'translation' => [
        'domain' => env('LINGUA_DOMAIN', 'generic'),
        'formality' => env('LINGUA_FORMALITY', 'formal'),
        'brand_terms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LINGUA_BRAND_TERMS', ''))
        ))),
        'glossary' => [],
        'preserve_enabled' => (bool) env('LINGUA_PRESERVE', true),
        'preserve_patterns' => [],

        // Fragments per LLM round-trip. Higher = fewer sequential calls per
        // page = much faster cold translation. Tune down only if you hit the
        // model's output-token ceiling on very long fragments.
        'chunk_size' => (int) env('LINGUA_CHUNK_SIZE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | cache_ttl: time-to-live in minutes (default: 60 days).
    */
    'cache_driver' => env('LINGUA_CACHE_DRIVER', 'file'),
    'cache_ttl' => env('LINGUA_CACHE_TTL', 86400),

    /*
    | Browser cache busting. The Cache-Control header set on every HTML page we
    | serve, so browsers ALWAYS revalidate and never show a stale cached page —
    | visitors never have to clear their browser cache after a content update.
    | The default revalidates without disabling caching entirely. Set to an
    | empty string to leave the host app's own caching headers untouched.
    */
    'browser_cache_control' => env('LINGUA_BROWSER_CACHE_CONTROL', 'no-cache, must-revalidate'),

    /*
    |--------------------------------------------------------------------------
    | Async Translation (Pilar 1)
    |--------------------------------------------------------------------------
    | OPT-IN. When enabled AND your queue driver is non-sync (database, redis),
    | LinguaLayer serves the first visit immediately in the source language with
    | a "Translating…" banner, runs a background TranslatePageJob, and the JS
    | reloads once it completes. Second visits are instant (cached).
    |
    | This REQUIRES a running worker: `php artisan queue:work --queue=lingua`.
    | It is OFF by default on purpose: Laravel 11/12 default QUEUE_CONNECTION to
    | `database`, so auto-enabling async whenever the driver is non-sync used to
    | dispatch background jobs on worker-less hosts that never ran them — leaving
    | pages untranslated and the banner spinning. With async off, every page is
    | translated inline (atomic) and page-cached, so repeat visits are instant
    | without any worker. Gateway (managed) installs are ALWAYS inline.
    |
    | queue_name: the queue to dispatch TranslatePageJob on.
    */
    'async' => (bool) env('LINGUA_ASYNC', false),
    'queue_name' => env('LINGUA_QUEUE', 'lingua'),

    /*
    |--------------------------------------------------------------------------
    | Middleware Toggles
    |--------------------------------------------------------------------------
    */
    'translate_response' => env('LINGUA_TRANSLATE_RESPONSE', true),
    'translate_request' => env('LINGUA_TRANSLATE_REQUEST', true),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (requests per minute, per client IP)
    |--------------------------------------------------------------------------
    | input: /lingua/translate-input — form-field translation on submit.
    | dom:   /lingua/translate-dom    — dynamic DOM fragments (burstier; a
    |        single SPA page can emit many fragments at once).
    | Tune per deployment; the defaults are generous enough for dashboards
    | while still capping abuse.
    */
    'throttle' => [
        'input' => (int) env('LINGUA_THROTTLE_INPUT', 200),
        'dom' => (int) env('LINGUA_THROTTLE_DOM', 600),

        // Hard per-request caps (defense against cost-amplification): a single
        // call to /lingua/translate-input or /translate-dom may carry at most
        // this many fields and this many total bytes. Oversized requests are
        // served back UNCHANGED (never blocked, never sent to the LLM/Gateway),
        // so a caller cannot fan one request out into a huge billed batch.
        'max_fields' => (int) env('LINGUA_MAX_FIELDS', 200),
        'max_bytes' => (int) env('LINGUA_MAX_BYTES', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    */
    'excluded_routes' => [
        'api/*',
        'lingua/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm-up URLs
    |--------------------------------------------------------------------------
    | Extra paths the `php artisan lingua:warm` command should pre-translate
    | at deploy time. Add parameterized routes here as concrete URLs, or any
    | public page the auto-discovery misses. Paths are joined with APP_URL.
    |
    | Example:
    |   'warm_urls' => ['/', '/pricing', '/blog/featured'],
    */
    'warm_urls' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded Form Fields
    |--------------------------------------------------------------------------
    | Input names that are NEVER translated (passwords, tokens, emails, etc.).
    */
    'excluded_fields' => [
        'password',
        'password_confirmation',
        '_token',
        'token',
        'email',
        'phone',
        'tel',
        'url',
        'file',
        'image',
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Field Translation Patterns (Pilar 3)
    |--------------------------------------------------------------------------
    | Fields whose names CONTAIN any substring in 'skip' are never translated,
    | even if they have text content. Useful for identity fields (name, company)
    | that should never be altered by the translation layer.
    |
    | Matching is case-insensitive substring match against the field's name attr.
    */
    'translate_field_patterns' => [
        'skip' => [
            'name', 'firstname', 'first_name', 'lastname', 'last_name',
            'username', 'company', 'organization', 'address', 'city',
            'country', 'zip', 'postal', 'code', 'nif', 'dni', 'cif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Skip Selectors (dynamic content opt-out)
    |--------------------------------------------------------------------------
    | Tag/class/id/attribute matchers whose subtree must NEVER be translated.
    | This is opt-in (default empty) — the translator already supports the
    | built-in opt-outs translate="no", class="notranslate", class="lingua-skip",
    | and data-lingua="skip".
    |
    | Use this list when you cannot edit the host markup but still need to keep
    | dynamic regions (modals showing user data, patient names, dashboards…)
    | out of the translation layer.
    |
    | Supported syntax:
    |   '.modal'                     class match (any element with class "modal")
    |   '#user-info'                 id match
    |   '[role="dialog"]'            attribute equality (single or double quotes)
    |   'table.data-table'           tag + class
    |   '.user-name', '.patient-name'
    |
    | Note: matching is done against ancestor chain — placing a selector here
    | excludes the element AND every descendant.
    */
    'skip_selectors' => [
        // '.modal',
        // '[role="dialog"]',
        // '.user-name', '.patient-name',
        // '.dynamic-content',
    ],

    /*
    |--------------------------------------------------------------------------
    | Language Selector UI
    |--------------------------------------------------------------------------
    */
    'selector_position' => env('LINGUA_SELECTOR_POSITION', 'top-right'),
    'selector_style' => env('LINGUA_SELECTOR_STYLE', 'flags'),

    /*
    |--------------------------------------------------------------------------
    | Auto Detection
    |--------------------------------------------------------------------------
    */
    'auto_detect' => env('LINGUA_AUTO_DETECT', true),

    /*
    |--------------------------------------------------------------------------
    | Translation Quality Scoring (Pilar 4)
    |--------------------------------------------------------------------------
    | When auto_score is true, TranslatePageJob samples 3 texts per page after
    | each async translation and calls Gemini to rate accuracy (1-10).
    | Low-score translations (< 7) are invalidated and re-translated on the
    | next request. Scores are stored in cache and viewable at /lingua/quality.
    |
    | quality_secret: set a secret key to protect the dashboard.
    |   Access: /lingua/quality?key=YOUR_SECRET
    |   Leave empty to allow access without a key (not recommended for production).
    */
    'auto_score' => env('LINGUA_AUTO_SCORE', false),
    'quality_secret' => env('LINGUA_QUALITY_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Few-Shot Learning (Pilar 5)
    |--------------------------------------------------------------------------
    | When enabled, LinguaLayer stores high-quality translations (score >= 8)
    | in the lingua_training_samples table and injects a selection of them into
    | the Gemini system prompt as examples on each subsequent translation batch.
    |
    | Prerequisites:
    |   1. Run: php artisan migrate  (creates lingua_training_samples table)
    |   2. Set LINGUA_AUTO_SCORE=true  (scoring feeds the sample pool)
    |
    | few_shot_max_examples: number of examples injected per prompt (default: 5).
    | few_shot_cache_hours:  how long to cache the example set per language pair.
    |                        Only non-empty sets are cached — if the table is
    |                        empty, the DB is re-checked on every request.
    |
    | Monitor with: php artisan lingua:fewshot-stats
    */
    'few_shot_enabled' => env('LINGUA_FEW_SHOT_ENABLED', false),
    'few_shot_max_examples' => env('LINGUA_FEW_SHOT_MAX_EXAMPLES', 5),
    'few_shot_cache_hours' => env('LINGUA_FEW_SHOT_CACHE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Persistent Storage (Pilar 6)
    |--------------------------------------------------------------------------
    | LinguaLayer can persist every translation in your application database
    | (table: lingua_translations) so subsequent visits hit zero Gemini calls
    | even after the cache expires. Run `php artisan migrate` once to enable.
    |
    | storage_driver:
    |   'database' — DB-first lookup, cache as fallback (recommended)
    |   'cache'    — legacy: cache-only, no persistence
    |   'hybrid'   — read both, but write only to BD (transition mode)
    |
    | translations_track_url:
    |   Store the request URL alongside each translation so the dashboard can
    |   show "translations on this page" and the change-detector knows where
    |   to look for similar previous strings.
    |
    | translations_change_threshold:
    |   When a NEW source text appears on a page that already has translations,
    |   compare against the existing ones. If similar_text similarity ≥ this
    |   value (0..1), mark the previous translation obsolete automatically.
    |
    | Cleanup (nightly job, scheduled at 04:00):
    |   translations_obsolete_days — mark as obsolete after N days unseen
    |   translations_delete_days   — delete obsolete rows after M more days
    |   translations_archive       — export to JSONL before deleting
    */
    'storage_driver' => env('LINGUA_STORAGE', 'database'),
    'translations_cleanup_enabled' => env('LINGUA_CLEANUP', true),
    'translations_archive' => env('LINGUA_ARCHIVE', true),
    'translations_obsolete_days' => env('LINGUA_OBSOLETE_DAYS', 30),
    'translations_delete_days' => env('LINGUA_DELETE_DAYS', 90),
    'translations_track_url' => env('LINGUA_TRACK_URL', true),
    'translations_change_threshold' => env('LINGUA_CHANGE_THRESHOLD', 0.8),

    /*
    |--------------------------------------------------------------------------
    | Target Languages override (Fase 5)
    |--------------------------------------------------------------------------
    | Comma-separated list of target language codes the host wants exposed in
    | the selector. When set, supported_languages above is filtered down to
    | this set + the source language. Useful when the package ships with a
    | broad default set but a particular host only needs (en,fr).
    */
    'target_languages' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LINGUA_TARGET_LANGUAGES', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Autonomous Agent (Fase 5)
    |--------------------------------------------------------------------------
    | OPT-IN — disabled by default since v1.7.0. When enabled, the agent
    | proactively pre-translates the host site, watches for content drift on
    | existing pages, and re-evaluates low-score translations on a nightly
    | schedule. Because that spends the HOST's own LLM budget automatically, it
    | never turns itself on: enable it explicitly with LINGUA_AGENT_ENABLED=true.
    | The dashboard's "Scan & translate now" button works regardless. The
    | dashboard at /lingua/quality renders real-time progress bars by polling
    | /lingua/quality/progress.
    |
    | Requires the host app to have:
    |   • php artisan queue:work --queue=lingua    (a worker for the jobs)
    |   • php artisan schedule:work                (so the schedule fires)
    |
    | discovery_interval_hours:    minimum hours between full route scans
    | change_check_interval_hours: minimum hours between drift checks
    | quality_check_at:            HH:MM time of day for quality re-evaluation
    | quality_threshold:           translations with score < N are invalidated
    | auto_warm_new_pages:         after discovery, enqueue WarmAllPagesJob
    | auto_translate_changes:      after drift detection, retranslate affected
    | max_pages_per_run:           safety cap per agent run
    | notification_channel:        log | webhook | slack | email
    | webhook_url:                 used when channel = webhook
    */
    'agent' => [
        'enabled' => (bool) env('LINGUA_AGENT_ENABLED', false),
        'discovery_interval_hours' => (int) env('LINGUA_AGENT_DISCOVERY_HOURS', 6),
        'change_check_interval_hours' => (int) env('LINGUA_AGENT_CHANGE_HOURS', 1),
        'quality_check_at' => (string) env('LINGUA_AGENT_QUALITY_AT', '02:00'),
        'quality_threshold' => (int) env('LINGUA_AGENT_QUALITY_THRESHOLD', 7),
        'auto_warm_new_pages' => (bool) env('LINGUA_AGENT_AUTO_WARM', true),
        'auto_translate_changes' => (bool) env('LINGUA_AGENT_AUTO_RETRANSLATE', true),
        'max_pages_per_run' => (int) env('LINGUA_AGENT_MAX_PAGES', 50),

        // Opportunistic warm "tick": fires from the middleware's terminate()
        // (AFTER the response is sent, so it never blocks the visitor) at most
        // once per tick_interval_minutes. This pre-warms pages on hosts with NO
        // cron and NO queue worker — the zero-touch fallback. On such hosts the
        // job runs inline, bounded by max_pages_per_run, so keep that LOW
        // (e.g. LINGUA_AGENT_MAX_PAGES=5) where there is no worker.
        'tick_enabled' => (bool) env('LINGUA_AGENT_TICK', true),
        'tick_interval_minutes' => (int) env('LINGUA_AGENT_TICK_MINUTES', 30),

        // Cron-driven worker-less pre-warm: `lingua:warm` runs INLINE every
        // minute inside `schedule:run`, bounded by warm_max_seconds, skipping
        // already-cached pages. With ONE cron line and NO queue worker this
        // keeps every page warm and auto-translates a newly-enabled language
        // within ~a minute. Set LINGUA_AGENT_AUTO_WARM_CRON=false to disable.
        'auto_warm' => (bool) env('LINGUA_AGENT_AUTO_WARM_CRON', true),
        'warm_max_seconds' => (int) env('LINGUA_AGENT_WARM_SECONDS', 50),

        'notification_channel' => (string) env('LINGUA_AGENT_NOTIFY', 'log'),
        'webhook_url' => (string) env('LINGUA_AGENT_WEBHOOK_URL', ''),

        /*
        |----------------------------------------------------------------------
        | Agent-only route exclusions
        |----------------------------------------------------------------------
        | Routes the agent's warm/discovery jobs MUST NOT touch (return
        | redirects to auth, are JSON, can't be reached without a session,
        | etc.) but where the regular middleware should STILL inject the
        | language selector and translate inline when a logged-in user
        | visits them.
        |
        | Different from `lingua.excluded_routes` above, which removes the
        | route from the middleware entirely (no selector, no translation).
        | Reserve `excluded_routes` for true API endpoints; put the auth-
        | required UI prefixes here.
        */
        'excluded_routes' => [
            // Add per-host paths via env LINGUA_AGENT_EXCLUDED_ROUTES if you
            // prefer not to touch this file. Defaults are empty so the
            // package ships with the same behaviour as before.
        ],
    ],

];

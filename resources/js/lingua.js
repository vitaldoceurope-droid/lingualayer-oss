/**
 * LinguaLayer — Bidirectional AI Translation Layer
 *
 * Cambios v1.6.0 (2026-06-29):
 *  - Caché de traducción DOM movida de sessionStorage → localStorage (con
 *    versión + TTL): las visitas repetidas (otra pestaña, otro día, el móvil)
 *    son instantáneas en vez de re-traducir toda la SPA.
 *  - Todo acceso a storage va por wrappers seguros; init() aísla cada
 *    subsistema → un throw (p.ej. SecurityError en Safari iOS) ya no tumba la
 *    traducción ni el selector (fallo "no funciona en el móvil").
 *  - Selector mobile-safe: tap targets ≥40px en táctil, safe-area-inset, prefijo
 *    -webkit-backdrop-filter. Banner async: maneja 'unknown' y abandona si no
 *    hay worker (sin spinner colgado de 4 min).
 *
 * v1.3.0 — Plan C completo: traduce TODO el contenido user-facing
 *           incluyendo placeholders/title/alt/aria-* + DOM inicial + dinámico.
 *
 * Cambios v1.3.0 (2026-05-06):
 *  - Procesa el DOM INICIAL al cargar (antes solo mutaciones).
 *  - Lista de atributos ampliada (parity con HtmlTranslator server-side).
 *  - Cache sessionStorage por (lang, source) para evitar re-pedidos.
 *  - Reintentos exponenciales si el gateway falla (3 intentos máx).
 *  - Compatible con SPAs (React/Vue/Livewire/HTMX) sin tocar su código.
 *  - Bump versión visible en window.__linguaVersion para diagnóstico.
 */
(function () {
    'use strict';

    window.__linguaVersion = '1.6.2';

    // ─────────────────────────────────────────────────────────────
    // 1. Bootstrap — read config from meta tag
    // ─────────────────────────────────────────────────────────────
    const metaEl = document.querySelector('meta[name="lingua-config"]');
    if (!metaEl) return;

    let config;
    try {
        config = JSON.parse(metaEl.getAttribute('content'));
    } catch {
        return;
    }

    const {
        source_language: sourceLang,
        supported_languages: languages,
        selector_position: position = 'top-right',
        selector_style: style = 'flags',
        auto_detect: autoDetect = true,
        translate_input_url: translateUrl = '/lingua/translate-input',
        translate_dom_url: translateDomUrl = '/lingua/translate-dom',
        excluded_fields: excludedFields = [],
        skip_field_patterns: skipFieldPatterns = [],
    } = config;

    const lowerSkipPatterns = (skipFieldPatterns || []).map(s => String(s).toLowerCase());

    function fieldShouldSkip(name) {
        if (!name) return true;
        if (excludedFields.includes(name)) return true;
        const n = String(name).toLowerCase();
        return lowerSkipPatterns.some(p => n.includes(p));
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Language persistence helpers
    // ─────────────────────────────────────────────────────────────
    // Storage access can THROW (not just fail) on iOS Safari "Block All
    // Cookies", in-app webviews (Instagram/Facebook/LinkedIn) and partitioned
    // contexts. An unguarded localStorage read here would abort init() entirely
    // — no selector, no translation — which is a clean mobile-only total
    // failure. Every storage access goes through these safe wrappers.
    function safeLocalGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }
    function safeLocalSet(key, value) {
        try { localStorage.setItem(key, value); return true; } catch (e) { return false; }
    }
    function safeLocalRemove(key) {
        try { localStorage.removeItem(key); } catch (e) {}
    }
    function safeSessionGet(key) {
        try { return sessionStorage.getItem(key); } catch (e) { return null; }
    }

    function getCurrentLang() {
        return getCookie('lingua_lang') || safeLocalGet('lingua_lang') || sourceLang;
    }

    function setLang(lang) {
        setCookie('lingua_lang', lang, 365);
        safeLocalSet('lingua_lang', lang);
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Progress bar (top of page, GitHub-style)
    // ─────────────────────────────────────────────────────────────
    function createProgressBar() {
        const bar = document.createElement('div');
        bar.id = 'lingua-progress';
        bar.style.cssText = `
            position:fixed;top:0;left:0;width:0;height:3px;
            background:linear-gradient(90deg,#4f46e5,#7c3aed);
            z-index:999999;transition:width .3s ease;
            box-shadow:0 0 8px rgba(79,70,229,.6);
        `;
        document.body.prepend(bar);
        return bar;
    }
    function showProgress() {
        const bar = document.getElementById('lingua-progress') || createProgressBar();
        bar.style.width = '70%';
    }
    function completeProgress() {
        const bar = document.getElementById('lingua-progress');
        if (!bar) return;
        bar.style.width = '100%';
        setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => bar.remove(), 300); }, 300);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Async translation polling (Pilar 1)
    // ─────────────────────────────────────────────────────────────
    function checkTranslating() {
        const translatingMeta = document.querySelector('meta[name="lingua-translating"]');
        if (!translatingMeta) return;
        const pageHash = translatingMeta.getAttribute('content');
        if (!pageHash) return;

        const currentLangInfo = languages[getCurrentLang()];
        const langName = currentLangInfo ? currentLangInfo.name : getCurrentLang();

        // The banner is created LAZILY — only once a worker actually reports
        // 'processing'. On a worker-less host (every gateway client, and any
        // install that didn't opt into async + run a queue:work) the status
        // stays 'queued', so the banner NEVER appears and we give up silently.
        // This fixes the lingering-spinner symptom for ALL clients with no
        // server-side change — it ships centrally via this script.
        let banner = null;
        function ensureBanner(failed) {
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'lingua-translating-banner';
                banner.style.cssText = `
                    position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
                    background:#1e1e2e;color:#fff;border-radius:12px;
                    padding:12px 20px;display:flex;align-items:center;gap:10px;
                    z-index:99999;box-shadow:0 4px 24px rgba(0,0,0,.25);
                    font-family:system-ui,sans-serif;font-size:13px;
                    animation:lingua-slide-up .3s ease;max-width:90vw;
                `;
                document.body.appendChild(banner);
            }
            banner.innerHTML = failed
                ? `<span>⚠</span><span>Translation failed — showing original language</span>`
                : `<span style="display:inline-block;width:14px;height:14px;border:2px solid #7c3aed;border-top-color:transparent;border-radius:50%;animation:lingua-spin .6s linear infinite;flex-shrink:0"></span><span>Translating to <strong>${langName}</strong>…</span>`;
        }

        let attempts = 0;
        const maxAttempts = 120;
        const stop = () => { clearInterval(interval); if (banner) banner.remove(); };
        const interval = setInterval(() => {
            attempts++;
            if (attempts > maxAttempts) { stop(); return; }
            fetch('/lingua/status/' + encodeURIComponent(pageHash), { cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    const s = d && d.status;
                    if (s === 'ready') { clearInterval(interval); if (banner) banner.remove(); showProgress(); setTimeout(() => location.reload(), 100); }
                    // A worker flips 'queued' → 'processing' within seconds — only
                    // THEN show the banner (there is real work in flight).
                    else if (s === 'processing') { ensureBanner(false); }
                    else if (s === 'failed') { clearInterval(interval); ensureBanner(true); banner.style.background = '#7f1d1d'; setTimeout(() => { if (banner) banner.remove(); }, 4000); }
                    // 'unknown' = no status row exists (key expired/flushed) → stop.
                    else if (s === 'unknown') { stop(); }
                    // Still 'queued' after ~10s and no worker ever picked it up →
                    // none is running; give up silently (no banner was ever shown).
                    else if (s === 'queued' && attempts >= 5) { stop(); }
                })
                .catch(() => {});
        }, 2000);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Language Selector Widget
    // ─────────────────────────────────────────────────────────────
    function buildSelector() {
        const currentLang = getCurrentLang();
        const wrapper = document.createElement('div');
        wrapper.id = 'lingua-selector';

        const isRTL = document.documentElement.dir === 'rtl'
            || /^(ar|he|fa|ur)\b/i.test(document.documentElement.lang || '');
        const effectivePosition = isRTL
            ? position.replace('right', '__R__').replace('left', 'right').replace('__R__', 'left')
            : position;

        // env(safe-area-inset-*) keeps the widget clear of the iOS notch / home
        // indicator; it resolves to 0 on devices/browsers without a safe area.
        const positionMap = {
            'top-right':    'top:calc(16px + env(safe-area-inset-top));right:calc(16px + env(safe-area-inset-right))',
            'top-left':     'top:calc(16px + env(safe-area-inset-top));left:calc(16px + env(safe-area-inset-left))',
            'bottom-right': 'bottom:calc(16px + env(safe-area-inset-bottom));right:calc(16px + env(safe-area-inset-right))',
            'bottom-left':  'bottom:calc(16px + env(safe-area-inset-bottom));left:calc(16px + env(safe-area-inset-left))',
        };
        const pos = positionMap[effectivePosition] || positionMap['top-right'];
        const entries = Object.entries(languages);

        const switchTo = (code) => {
            if (code === currentLang) return;
            showProgress();
            setLang(code);
            setTimeout(() => location.reload(), 80);
        };

        // Many languages (or style=dropdown): a searchable dropdown that scales
        // to dozens of languages. A flag row only works for a handful.
        const useDropdown = style === 'dropdown' || entries.length > 8;

        if (useDropdown) {
            wrapper.style.cssText = `position:fixed;${pos};z-index:99998;font-family:system-ui,-apple-system,sans-serif;`;
            const cur = languages[currentLang] || (entries[0] && entries[0][1]) || { flag: '🌐', name: '' };

            const btn = document.createElement('button');
            btn.setAttribute('aria-haspopup', 'listbox');
            btn.style.cssText = `display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid rgba(0,0,0,.12);border-radius:999px;padding:7px 13px;box-shadow:0 2px 12px rgba(0,0,0,.14);cursor:pointer;font:14px system-ui;color:#16161d;`;
            btn.innerHTML = `<span style="font-size:17px;line-height:1">${cur.flag}</span><span style="font-weight:600">${cur.name}</span><span style="opacity:.5;font-size:11px">▾</span>`;

            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            const vSide = effectivePosition.indexOf('top') === 0 ? 'top:48px' : 'bottom:48px';
            const hSide = effectivePosition.indexOf('right') !== -1 ? 'right:0' : 'left:0';
            panel.style.cssText = `display:none;position:absolute;${vSide};${hSide};width:248px;background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.2);overflow:hidden;`;

            const search = document.createElement('input');
            search.type = 'search';
            search.setAttribute('aria-label', 'Search language');
            search.placeholder = '🔎';
            search.style.cssText = `width:100%;border:0;border-bottom:1px solid #eee;padding:11px 14px;font:14px system-ui;outline:none;box-sizing:border-box;color:#16161d;`;

            const list = document.createElement('div');
            list.style.cssText = `max-height:300px;overflow-y:auto;`;

            const renderList = (filter) => {
                list.innerHTML = '';
                entries.forEach(([code, info]) => {
                    if (filter && (info.name + ' ' + code).toLowerCase().indexOf(filter) === -1) return;
                    const active = code === currentLang;
                    const item = document.createElement('button');
                    item.setAttribute('data-lingua-code', code);
                    item.setAttribute('role', 'option');
                    item.style.cssText = `display:flex;align-items:center;gap:9px;width:100%;border:0;background:${active ? '#eef2ff' : 'transparent'};padding:9px 14px;cursor:pointer;font:14px system-ui;text-align:left;color:#16161d;`;
                    item.innerHTML = `<span style="font-size:16px;line-height:1">${info.flag}</span><span${active ? ' style="font-weight:600"' : ''}>${info.name}</span>`;
                    item.addEventListener('mouseenter', () => { item.style.background = '#f4f4f7'; });
                    item.addEventListener('mouseleave', () => { item.style.background = active ? '#eef2ff' : 'transparent'; });
                    item.addEventListener('click', () => { if (active) { panel.style.display = 'none'; } else { switchTo(code); } });
                    list.appendChild(item);
                });
            };
            renderList('');
            search.addEventListener('input', () => renderList(search.value.trim().toLowerCase()));
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = panel.style.display === 'block';
                panel.style.display = open ? 'none' : 'block';
                if (!open) { search.value = ''; renderList(''); setTimeout(() => search.focus(), 30); }
            });
            document.addEventListener('click', (e) => { if (!wrapper.contains(e.target)) panel.style.display = 'none'; });

            panel.appendChild(search);
            panel.appendChild(list);
            wrapper.appendChild(btn);
            wrapper.appendChild(panel);
            document.body.appendChild(wrapper);
            return;
        }

        // Few languages: the compact flag row.
        wrapper.style.cssText = `
            position:fixed;${pos};
            z-index:99998;display:flex;align-items:center;gap:6px;
            background:rgba(255,255,255,.95);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);
            border:1px solid rgba(0,0,0,.12);border-radius:999px;
            padding:6px 10px;box-shadow:0 2px 12px rgba(0,0,0,.12);
            font-family:system-ui,sans-serif;font-size:14px;
        `;
        entries.forEach(([code, info]) => {
            const btn = document.createElement('button');
            btn.title = info.name;
            btn.setAttribute('aria-label', info.name);
            btn.setAttribute('data-lingua-code', code);
            btn.setAttribute('data-lingua-active', code === currentLang ? 'true' : 'false');
            btn.style.cssText = `
                background:none;border:none;cursor:pointer;padding:2px 4px;
                border-radius:6px;font-size:18px;line-height:1;
                transition:transform .15s,opacity .15s;
                opacity:${code === currentLang ? '1' : '.45'};
                transform:${code === currentLang ? 'scale(1.2)' : 'scale(1)'};
            `;
            if (style === 'names') { btn.textContent = info.name; btn.style.fontSize = '12px'; }
            else if (style === 'both') { btn.textContent = `${info.flag} ${info.name}`; btn.style.fontSize = '12px'; }
            else { btn.textContent = info.flag; }

            btn.addEventListener('click', () => switchTo(code));
            wrapper.appendChild(btn);
        });
        document.body.appendChild(wrapper);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Form Input Interceptor (Pilar 3)
    // ─────────────────────────────────────────────────────────────
    function interceptForms() {
        const currentLang = getCurrentLang();
        if (currentLang === sourceLang) return;
        document.addEventListener('submit', async (e) => {
            const form = e.target;
            if (!form || form.dataset.linguaProcessed) return;

            // Bug v1.3.1: NO interceptar forms SPA (sin action server-side).
            // Forms React/Vue/HTMX manejan el submit con onSubmit + fetch + preventDefault
            // y NO tienen `action` HTML attribute. Si interceptamos y luego hacemos
            // form.submit() al final, generamos un POST tradicional a la URL actual,
            // recargando la página y rompiendo el SPA.
            //
            // Reglas para SALTAR interceptado (dejar al cliente manejarlo):
            //   - form sin action explícito (data o vacío).
            //   - form con data-lingua-intercept="false" (opt-out manual).
            //   - form cuya action apunta a la misma página (no tiene sentido interceptar).
            const actionAttr = (form.getAttribute('action') || '').trim();
            if (!actionAttr || form.dataset.linguaIntercept === 'false') {
                return;  // Deja que React/Vue/JS manejen el submit normalmente.
            }

            e.preventDefault();
            const fields = {};
            const inputs = form.querySelectorAll('input[type=text],input[type=search],input:not([type]),textarea');
            inputs.forEach((input) => {
                const name = input.name || input.id;
                if (fieldShouldSkip(name)) return;
                const val = (input.value || '').trim();
                if (val.length >= 2) fields[name] = val;
            });
            if (Object.keys(fields).length === 0) { form.dataset.linguaProcessed = '1'; form.submit(); return; }
            const submitBtn = form.querySelector('[type=submit]');
            let originalBtnContent = null;
            if (submitBtn) {
                originalBtnContent = submitBtn.innerHTML;
                submitBtn.innerHTML = `<span style="display:inline-block;width:16px;height:16px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:lingua-spin .6s linear infinite"></span>`;
                submitBtn.disabled = true;
            }
            try {
                const csrfMeta = document.querySelector('meta[name=csrf-token]');
                const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
                const res = await fetch(translateUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Lingua-Lang': currentLang },
                    body: JSON.stringify({ fields, source_lang: currentLang }),
                });
                if (res.ok) {
                    const data = await res.json();
                    Object.entries(data.fields || {}).forEach(([name, val]) => {
                        const input = form.querySelector(`[name="${name}"]`) || form.querySelector(`#${name}`);
                        if (input) input.value = val;
                    });
                }
            } catch {}
            finally {
                if (submitBtn && originalBtnContent !== null) { submitBtn.innerHTML = originalBtnContent; submitBtn.disabled = false; }
                form.dataset.linguaProcessed = '1';
                form.submit();
            }
        }, { capture: true });
    }

    // ─────────────────────────────────────────────────────────────
    // 7. Auto Language Detection Banner
    // ─────────────────────────────────────────────────────────────
    function autoDetectLanguage() {
        if (!autoDetect) return;
        if (safeLocalGet('lingua_lang')) return;
        const browserLang = (navigator.language || navigator.userLanguage || '').slice(0, 2).toLowerCase();
        const supported = Object.keys(languages);
        if (!supported.includes(browserLang) || browserLang === sourceLang) return;
        const langInfo = languages[browserLang];
        const banner = document.createElement('div');
        banner.id = 'lingua-detect-banner';
        banner.style.cssText = `
            position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
            background:#1e1e2e;color:#fff;border-radius:12px;
            padding:14px 20px;display:flex;align-items:center;gap:12px;
            z-index:99999;box-shadow:0 4px 24px rgba(0,0,0,.25);
            font-family:system-ui,sans-serif;font-size:14px;
            animation:lingua-slide-up .3s ease;max-width:90vw;
        `;
        banner.innerHTML = `
            <span>${langInfo.flag}</span>
            <span>Continue in <strong>${langInfo.name}</strong>?</span>
            <button id="lingua-yes" style="background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:6px 14px;cursor:pointer;font-size:13px;">Yes</button>
            <button id="lingua-no" style="background:transparent;color:#aaa;border:1px solid #444;border-radius:8px;padding:6px 14px;cursor:pointer;font-size:13px;">No</button>
        `;
        document.body.appendChild(banner);
        document.getElementById('lingua-yes').addEventListener('click', () => { showProgress(); setLang(browserLang); banner.remove(); setTimeout(() => location.reload(), 80); });
        document.getElementById('lingua-no').addEventListener('click', () => { setLang(sourceLang); banner.remove(); });
    }

    // ─────────────────────────────────────────────────────────────
    // 8. Inject CSS keyframes
    // ─────────────────────────────────────────────────────────────
    function injectStyles() {
        const styleEl = document.createElement('style');
        styleEl.textContent = `
            @keyframes lingua-spin { to { transform: rotate(360deg); } }
            @keyframes lingua-slide-up { from { opacity: 0; transform: translateX(-50%) translateY(20px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
            /* Touch devices: enforce a tappable target on the selector buttons
               (emoji flags are ~24px otherwise — below the 44px guideline). */
            @media (pointer: coarse) {
                #lingua-selector button { min-width: 40px; min-height: 40px; }
            }
        `;
        document.head.appendChild(styleEl);
    }

    // ─────────────────────────────────────────────────────────────
    // 9. Universal DOM scanner — texto + atributos + DOM inicial + dinámico
    // ─────────────────────────────────────────────────────────────
    function setupUniversalTranslator() {
        const currentLang = getCurrentLang();
        if (currentLang === sourceLang) return;
        if (!('MutationObserver' in window)) return;

        // Atributos visibles ampliada — parity completa con HtmlTranslator server.
        const VISIBLE_ATTRS = [
            'placeholder', 'title', 'alt',
            'aria-label', 'aria-description', 'aria-placeholder',
            'aria-roledescription', 'aria-valuetext',
            'data-tooltip', 'data-title', 'data-original-title',
            'data-confirm', 'data-placeholder', 'label',
        ];
        // Tags cuyo TEXT nunca se traduce. INPUT/TEXTAREA están aquí porque
        // no tienen text children visibles (su valor es .value, separado).
        const SKIP_TAGS_TEXT = new Set([
            'SCRIPT', 'STYLE', 'TEMPLATE', 'SVG', 'MATH',
            'NOSCRIPT', 'PRE', 'CODE', 'TEXTAREA', 'INPUT',
        ]);
        // Tags cuyos ATRIBUTOS no se procesan (sus atributos no son user-facing).
        // INPUT/TEXTAREA SÍ procesan atributos (placeholder, title, aria-label, etc.).
        const SKIP_TAGS_ATTR = new Set([
            'SCRIPT', 'STYLE', 'TEMPLATE', 'SVG', 'MATH', 'NOSCRIPT',
        ]);

        // Cross-session translation memory in localStorage. sessionStorage
        // (the old store) was wiped the moment the tab closed, so every fresh
        // visit — a new tab, the next day, and especially a phone that never
        // built up a tab cache — re-translated the whole SPA from scratch. This
        // survives tab/session/app restarts. Keyed by the SOURCE text, so when
        // the host's content changes the new string is simply absent → natural
        // miss → re-translate, while unchanged strings hit instantly with no
        // gateway round-trip. Bounded by a version stamp (bump CACHE_VERSION to
        // invalidate every client at once, since lingua.js is served centrally)
        // and a TTL.
        const CACHE_VERSION = 3;          // bump to invalidate every client's cache (3: drop entries that may hold a failed fragment echoed back as source)
        const CACHE_TTL_MS = 7 * 864e5;   // 7 days
        const CACHE_KEY = '__lingua_cache_' + currentLang;
        let cache;
        try {
            const raw = JSON.parse(safeLocalGet(CACHE_KEY) || 'null');
            cache = (raw && raw.v === CACHE_VERSION && raw.t && (Date.now() - raw.t) < CACHE_TTL_MS && raw.m) ? raw.m : {};
        } catch (e) { cache = {}; }
        let cacheDirty = false;
        function persistCache() {
            if (!cacheDirty) return;
            if (!safeLocalSet(CACHE_KEY, JSON.stringify({ v: CACHE_VERSION, t: Date.now(), m: cache }))) {
                // Quota or SecurityError — drop this language's blob, stop trying.
                safeLocalRemove(CACHE_KEY);
            }
            cacheDirty = false;
        }
        // Throttle persist cada 1s para no saturar.
        setInterval(persistCache, 1000);

        const seen = new WeakSet();
        let pending = [];
        let flushTimer = null;

        function shouldSkipForText(el) {
            if (!el || el.nodeType !== 1) return true;
            if (SKIP_TAGS_TEXT.has(el.tagName)) return true;
            if (el.closest('#lingua-selector, #lingua-translating-banner, #lingua-detect-banner, #lingua-progress')) return true;
            if (el.closest('[translate="no"], .notranslate, .lingua-skip, [data-lingua="skip"]')) return true;
            return false;
        }
        function shouldSkipForAttr(el) {
            if (!el || el.nodeType !== 1) return true;
            if (SKIP_TAGS_ATTR.has(el.tagName)) return true;
            if (el.closest('#lingua-selector, #lingua-translating-banner, #lingua-detect-banner, #lingua-progress')) return true;
            if (el.closest('[translate="no"], .notranslate, .lingua-skip, [data-lingua="skip"]')) return true;
            return false;
        }
        // Compat: shouldSkipElement usado en el observer de mutaciones para textos.
        const shouldSkipElement = shouldSkipForText;

        function shouldSkipAttrValue(val) {
            if (!val) return true;
            const t = val.trim();
            if (t.length < 2) return true;
            // Skip pure numbers, URLs, emails, codes that no necesitan traduccion.
            if (/^[\d\s\.\-\/:]+$/.test(t)) return true;
            if (/^https?:\/\//i.test(t)) return true;
            if (/^[\w.+-]+@[\w-]+\.[a-z]{2,}$/i.test(t)) return true;
            return false;
        }

        function applyCachedTranslation(item) {
            const cached = cache[item.original];
            if (!cached) return false;
            if (item.kind === 'text') {
                item.node.nodeValue = cached;
            } else if (item.kind === 'attr') {
                item.el.setAttribute(item.attr, cached);
                item.el['__lingua_' + item.attr] = cached;
            }
            return true;
        }

        function collectFromSubtree(root) {
            if (!root) return [];
            const items = [];

            // Text nodes (con SKIP_TAGS_TEXT — input/textarea/script/etc. fuera)
            if (root.nodeType === 1 || root.nodeType === 9 || root.nodeType === 11) {
                if (root.nodeType === 1 && shouldSkipForText(root)) {
                    // Aún así puede contener INPUT/TEXTAREA descendientes con
                    // atributos a traducir, así que NO retornamos aquí.
                } else {
                    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                        acceptNode(n) {
                            if (!n.nodeValue) return NodeFilter.FILTER_REJECT;
                            const t = n.nodeValue.trim();
                            if (t.length < 3) return NodeFilter.FILTER_REJECT;
                            if (shouldSkipForText(n.parentElement)) return NodeFilter.FILTER_REJECT;
                            // Skip only nodes already translated OK or currently in-flight.
                            // Failed/pending nodes stay collectable so a later sweep retries them.
                            if (seen.has(n) || n.__linguaInflight) return NodeFilter.FILTER_REJECT;
                            return NodeFilter.FILTER_ACCEPT;
                        },
                    });
                    let node;
                    while ((node = walker.nextNode())) {
                        items.push({ kind: 'text', node, original: node.nodeValue.trim() });
                    }
                }
            }

            // Atributos visibles (con SKIP_TAGS_ATTR — input/textarea SÍ procesan)
            const elIter = root.nodeType === 1
                ? [root, ...root.querySelectorAll('*')]
                : (root.nodeType === 9 || root.nodeType === 11) ? [...root.querySelectorAll('*')] : [];
            elIter.forEach(el => {
                if (shouldSkipForAttr(el)) return;
                VISIBLE_ATTRS.forEach(attr => {
                    if (!el.hasAttribute(attr)) return;
                    const val = (el.getAttribute(attr) || '').trim();
                    if (shouldSkipAttrValue(val)) return;
                    const key = '__lingua_' + attr;
                    // el[key] is set to the value ONLY once successfully translated,
                    // so a failed attr stays collectable for a later retry/sweep.
                    if (el[key] === val || el['__inflight_' + attr]) return;
                    items.push({ kind: 'attr', el, attr, original: val });
                });
            });

            return items;
        }

        function enqueue(items) {
            if (!items || items.length === 0) return;
            // Aplicar cache primero — los que estén cacheados se traducen instantáneamente
            // y no van al gateway.
            const remaining = [];
            items.forEach(item => {
                if (!applyCachedTranslation(item)) remaining.push(item);
            });
            if (remaining.length === 0) return;
            pending.push(...remaining);
            if (flushTimer) return;
            flushTimer = setTimeout(flush, 250);
        }

        // Mark/unmark a node as in-flight so a concurrent sweep won't re-send it,
        // while a FAILED node (in-flight cleared, not marked done) stays collectable.
        function markInflight(item, on) {
            if (item.kind === 'text') { item.node.__linguaInflight = on; }
            else { item.el['__inflight_' + item.attr] = on; }
        }

        const MAX_PER_REQUEST = 80;   // small, fast requests — big pages stream in batches
        const MAX_RETRIES = 5;        // ride out transient gateway / rate-limit failures

        function flush(retryAttempt) {
            flushTimer = null;
            if (pending.length === 0) return;

            // Send at most MAX_PER_REQUEST; reschedule the remainder right away so a
            // huge SPA page translates in steady small batches instead of one giant call.
            const batch = pending.splice(0, MAX_PER_REQUEST);
            if (pending.length > 0 && !flushTimer) flushTimer = setTimeout(() => flush(0), 60);

            batch.forEach(it => markInflight(it, true));
            const fields = {};
            batch.forEach((item, i) => { fields['n' + i] = item.original; });

            const csrfMeta = document.querySelector('meta[name=csrf-token]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

            fetch(translateDomUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Lingua-Lang': currentLang },
                body: JSON.stringify({ fields, target_lang: currentLang }),
            })
                .then(r => r.ok ? r.json() : Promise.reject('http-' + r.status))
                .then(data => {
                    if (!data || !data.fields) { batch.forEach(it => markInflight(it, false)); return; }
                    batch.forEach((item, i) => {
                        markInflight(item, false);
                        const translated = data.fields['n' + i];
                        // No value for this slot → leave it collectable so a sweep retries it.
                        if (translated === undefined || translated === null || translated === '') return;

                        if (item.kind === 'text') {
                            if (translated !== item.original) item.node.nodeValue = translated;
                            item.node.__linguaTranslatedTo = translated;    // remember our write → detect framework reverts
                            seen.add(item.node);                            // done — won't be re-collected
                        } else {
                            if (translated !== item.original) item.el.setAttribute(item.attr, translated);
                            item.el['__lingua_' + item.attr] = translated;  // done marker for attrs
                        }
                        // Cache ONLY genuine translations (translated != source).
                        // A fragment echoed back equal to its source may be a real
                        // no-op OR a fragment that FAILED and was returned as the
                        // original — caching the latter would freeze it untranslated
                        // for the cache TTL (this caused Spanish text to stick on the
                        // client after a transient gateway failure). Leaving it
                        // uncached lets a later sweep / visit retry it.
                        if (translated !== item.original) { cache[item.original] = translated; cacheDirty = true; }
                    });
                })
                .catch(() => {
                    // Whole batch failed — clear in-flight so nodes are retried, back off, re-queue.
                    batch.forEach(it => markInflight(it, false));
                    const attempt = (retryAttempt || 0) + 1;
                    if (attempt > MAX_RETRIES) return;   // a later periodic sweep still picks them up
                    pending.unshift(...batch);
                    if (!flushTimer) flushTimer = setTimeout(() => flush(attempt), Math.min(8000, 300 * Math.pow(2, attempt - 1)));
                });
        }

        // Observer para mutaciones futuras (SPAs, modales, etc.)
        const observer = new MutationObserver(mutations => {
            const items = [];
            for (const m of mutations) {
                m.addedNodes.forEach(n => {
                    if (n.nodeType === 1) items.push(...collectFromSubtree(n));
                    else if (n.nodeType === 3 && !seen.has(n) && !n.__linguaInflight) {
                        const t = n.nodeValue && n.nodeValue.trim();
                        if (t && t.length >= 3 && !shouldSkipElement(n.parentElement)) {
                            items.push({ kind: 'text', node: n, original: t });
                        }
                    }
                });
                // Atributos modificados de elementos existentes (ej. React updates)
                if (m.type === 'attributes' && m.target && m.target.nodeType === 1
                    && VISIBLE_ATTRS.includes(m.attributeName)) {
                    const el = m.target;
                    if (!shouldSkipElement(el)) {
                        const val = (el.getAttribute(m.attributeName) || '').trim();
                        if (!shouldSkipAttrValue(val)) {
                            const key = '__lingua_' + m.attributeName;
                            if (el[key] !== val && !el['__inflight_' + m.attributeName]) {
                                items.push({ kind: 'attr', el, attr: m.attributeName, original: val });
                            }
                        }
                    }
                }

                // Reactive frameworks (Vue/React/Livewire) re-render text nodes, reverting
                // our translation back to the source. Watch characterData and re-translate —
                // the cache re-applies instantly, no gateway round-trip. Bounded per node so a
                // hyper-reactive node can never thrash in an infinite tug-of-war.
                if (m.type === 'characterData' && m.target && m.target.nodeType === 3) {
                    const node = m.target;
                    const val = node.nodeValue && node.nodeValue.trim();
                    if (val && val.length >= 3 && !node.__linguaInflight
                        && node.__linguaTranslatedTo !== val
                        && !shouldSkipElement(node.parentElement)
                        && (node.__linguaReverts || 0) <= 5) {
                        node.__linguaReverts = (node.__linguaReverts || 0) + 1;
                        seen.delete(node);
                        items.push({ kind: 'text', node, original: val });
                    }
                }
            }
            enqueue(items);
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: VISIBLE_ATTRS,
            characterData: true,
        });

        // ⭐ FIX CRÍTICO v1.3.0: procesar el DOM INICIAL al arrancar.
        // Sin esto, los placeholders ya presentes (incluyendo los que React
        // renderizó antes de que MutationObserver estuviera activo) NO se
        // traducían — éste era el bug principal reportado.
        enqueue(collectFromSubtree(document.body));

        // Re-scan on SPA navigation. Modern SPAs (Vue Router / Nuxt / React Router)
        // navigate via the History API, not hash routing — so patch pushState/
        // replaceState and listen to popstate, plus the legacy hashchange.
        const reScan = () => setTimeout(() => enqueue(collectFromSubtree(document.body)), 350);
        window.addEventListener('hashchange', reScan);
        window.addEventListener('popstate', reScan);
        ['pushState', 'replaceState'].forEach(fn => {
            const orig = history[fn];
            if (typeof orig === 'function') {
                history[fn] = function () { const r = orig.apply(this, arguments); reScan(); return r; };
            }
        });

        // Periodic sweeps — SPAs render content over several seconds and the gateway
        // can fail a batch transiently; failed/late nodes stay collectable, so re-collecting
        // a few times after load retries them until the page is fully translated. Bounded.
        let sweeps = 0;
        const sweepTimer = setInterval(() => {
            enqueue(collectFromSubtree(document.body));
            if (++sweeps >= 10) clearInterval(sweepTimer);
        }, 1500);

        // Interaction sweeps — catch content lazy-loaded on scroll/click (infinite
        // lists, "load more", tabs, modals). Debounced; only collects new untranslated text.
        ['click', 'scroll', 'keydown'].forEach(ev => {
            let t;
            window.addEventListener(ev, () => {
                clearTimeout(t);
                t = setTimeout(() => enqueue(collectFromSubtree(document.body)), 500);
            }, { passive: true });
        });
    }

    // ─────────────────────────────────────────────────────────────
    // 10. Init
    // ─────────────────────────────────────────────────────────────
    function init() {
        // Each subsystem is isolated: a throw in one (e.g. a storage
        // SecurityError on mobile, or a selector edge case) must NEVER prevent
        // the others from running. Before this guard, a single early throw left
        // the page with no selector and no translation at all.
        [
            injectStyles,
            buildSelector,
            checkTranslating,
            interceptForms,
            setupUniversalTranslator,   // ← v1.3.0: cubre DOM inicial + dinámico + atributos completos
            autoDetectLanguage,
            completeProgress,
        ].forEach(function (step) {
            try { step(); } catch (e) { /* one subsystem down must not disable the rest */ }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

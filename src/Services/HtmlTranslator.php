<?php

namespace LinguaLayer\Services;

use DOMDocument;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Models\Translation;

class HtmlTranslator
{
    // Tags whose content DOMDocument must never touch
    private const PROTECT_PATTERN = '#<(script|style|template|svg|math|noscript|pre|code)(\s[^>]*)?>.*?</\1>#is';

    // Tags whose text content is not user-visible and must be skipped.
    // NOTE: <title> is intentionally NOT here — the browser tab title must be translated.
    private const SKIP_PARENT_TAGS = ['head'];

    // Attributes that carry user-visible copy and must be translated.
    private const TRANSLATABLE_ATTRS = [
        'placeholder',
        'title',
        'alt',
        'aria-label',
        'aria-description',
        'aria-placeholder',
        'aria-roledescription',
        'aria-valuetext',
        'data-tooltip',
        'data-title',
        'data-original-title',
        'data-confirm',
        'data-placeholder',
        'label',
    ];

    // <meta> tags whose `content` attribute is human-facing and should be translated.
    private const TRANSLATABLE_META = [
        'name' => [
            'description', 'keywords', 'application-name',
            'twitter:title', 'twitter:description', 'twitter:image:alt',
        ],
        'property' => [
            'og:title', 'og:description', 'og:site_name', 'og:image:alt',
        ],
        'itemprop' => [
            'name', 'description',
        ],
    ];

    /** @var array<int,array{tag:?string,class:?string,id:?string,attr:?string,attrValue:?string}> */
    private array $skipSelectors;

    public function __construct(
        private TranslatorInterface $translator,
        private ?TranslationStore $store = null,
    ) {
        $this->skipSelectors = $this->parseSkipSelectors(
            (array) config('lingua.skip_selectors', [])
        );
    }

    /**
     * Translate all visible text in an HTML string to the target language.
     *
     * Returns the translated HTML string, or null if any translation chunk
     * failed all retries — guaranteeing no partial (mixed-language) output.
     *
     * When a TranslationStore is wired up, reads happen DB-first: only texts
     * not yet in the persistent store reach Gemini. New translations are then
     * batchStore'd so the next page load skips the API entirely.
     */
    public function translate(string $html, string $targetLang, ?string $pageUrl = null): ?string
    {
        // Fail-safe wrapper: ANY error (bad HTML, missing memory table, cache
        // glitch, LLM blow-up) must degrade to "serve the original" (null), never
        // surface as a 500 to the host. Upholds the atomic guarantee.
        try {
            return $this->translateInner($html, $targetLang, $pageUrl);
        } catch (\Throwable $e) {
            Log::channel('single')->warning(
                '[LinguaLayer] HtmlTranslator fail-safe -> serving source',
                ['error' => $e->getMessage()]
            );

            return null;
        }
    }

    private function translateInner(string $html, string $targetLang, ?string $pageUrl = null): ?string
    {
        // Step 1: Extract script/style/svg/pre blocks and replace with unique
        // placeholders so DOMDocument never parses or modifies their content.
        [$safe, $protected] = $this->extractProtected($html);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">'.$safe, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $allTexts = [];
        $textRefs = [];  // [[DOMText, string originalText], ...]
        $attrRefs = [];  // [[DOMElement, string attr, string originalText], ...]

        foreach ($xpath->query('//text()') as $node) {
            /** @var DOMText $node */
            $trimmed = trim($node->nodeValue);
            if (mb_strlen($trimmed) < 3) {
                continue;
            }
            if (! $this->isInsideTranslatableTag($node)) {
                continue;
            }

            $textRefs[] = [$node, $trimmed];
            $allTexts[] = $trimmed;
        }

        foreach (self::TRANSLATABLE_ATTRS as $attr) {
            foreach ($xpath->query("//*[@{$attr}]") as $el) {
                if (! $this->elementIsTranslatable($el)) {
                    continue;
                }
                $val = trim($el->getAttribute($attr));
                if (mb_strlen($val) >= 3) {
                    $attrRefs[] = [$el, $attr, $val];
                    $allTexts[] = $val;
                }
            }
        }

        foreach ($xpath->query('//input[@type="submit"] | //input[@type="button"] | //input[@type="reset"]') as $input) {
            $val = trim($input->getAttribute('value'));
            if (mb_strlen($val) >= 3) {
                $attrRefs[] = [$input, 'value', $val];
                $allTexts[] = $val;
            }
        }

        // <meta> content (SEO: description, keywords, Open Graph, Twitter Cards)
        foreach (self::TRANSLATABLE_META as $keyAttr => $values) {
            foreach ($values as $v) {
                foreach ($xpath->query("//meta[@{$keyAttr}='{$v}' and @content]") as $meta) {
                    $val = trim($meta->getAttribute('content'));
                    if (mb_strlen($val) >= 3) {
                        $attrRefs[] = [$meta, 'content', $val];
                        $allTexts[] = $val;
                    }
                }
            }
        }

        if (empty($allTexts)) {
            return $this->restoreProtected($safe, $protected);
        }

        $unique = array_values(array_unique($allTexts));
        $uniqueIdx = array_flip($unique);

        $sourceLang = (string) config('lingua.source_language', 'en');

        // Three-tier resolution: DB → cache (inside translateBatch) → Gemini.
        // Anything missing from the DB is forwarded to translateBatch which
        // already knows how to skip its own per-fragment cache and only call
        // Gemini for the truly new strings.
        $fromDb = [];           // [position-index => translated string]
        $needsFromApi = $unique;      // values; reset below if Store is on

        if ($this->store !== null && $this->store->isAvailable()) {
            $byHash = $this->store->batchFind($unique, $sourceLang, $targetLang);
            $needsFromApi = [];

            foreach ($unique as $i => $text) {
                $hash = Translation::makeHash($text, $sourceLang);
                if (isset($byHash[$hash])) {
                    $fromDb[$i] = $byHash[$hash]->translated_text;
                } else {
                    $needsFromApi[$i] = $text;
                }
            }

            Log::channel('single')->info('[LinguaLayer] DB lookup', [
                'lang' => $targetLang,
                'hits' => count($fromDb),
                'misses' => count($needsFromApi),
            ]);
        }

        $translated = $fromDb;

        if (! empty($needsFromApi)) {
            // Preserve original positions when sending to Gemini so the result
            // map's keys can be merged back without re-aligning.
            $apiResult = $this->translator->translateBatch(array_values($needsFromApi), $targetLang);

            // null return = all retries exhausted → atomic failure
            if ($apiResult === null) {
                return null;
            }

            // Map sequential apiResult indexes back to original positions
            $apiPositions = array_keys($needsFromApi);
            $newItems = [];
            $threshold = (float) config('lingua.translations_change_threshold', 0.8);

            foreach ($apiPositions as $localIdx => $origIdx) {
                $sourceText = $needsFromApi[$origIdx];
                $translatedText = $apiResult[$localIdx] ?? $sourceText;
                $translated[$origIdx] = $translatedText;

                // Change detection: if a near-identical text used to exist on
                // this page, mark the old one obsolete. Only runs when we know
                // the page URL and the store is on — otherwise no-op.
                if ($pageUrl !== null && $this->store !== null) {
                    $similar = $this->store->detectChanges($sourceText, $pageUrl, $targetLang, $threshold);
                    if ($similar !== null) {
                        $similar->markObsolete();
                        Log::channel('single')->info('[LinguaLayer] Change detected', [
                            'page' => $pageUrl,
                            'old' => mb_substr($similar->source_text, 0, 80),
                            'new' => mb_substr($sourceText, 0, 80),
                        ]);
                    }
                }

                $newItems[] = [
                    'source' => $sourceText,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'translated' => $translatedText,
                    'model_used' => (string) config('lingua.gemini_model', 'gemini-2.5-flash'),
                    'page_url' => $pageUrl,
                ];
            }

            // Persist what we just got from the API so future pages hit the DB.
            if ($this->store !== null && $this->store->isAvailable()) {
                $this->store->batchStore($newItems);
            }
        }

        ksort($translated);

        foreach ($textRefs as [$node, $originalText]) {
            $key = $uniqueIdx[$originalText] ?? null;
            if ($key !== null && isset($translated[$key])) {
                $node->nodeValue = $translated[$key];
            }
        }

        foreach ($attrRefs as [$el, $attr, $originalText]) {
            $key = $uniqueIdx[$originalText] ?? null;
            if ($key !== null && isset($translated[$key])) {
                $el->setAttribute($attr, $translated[$key]);
            }
        }

        $output = $dom->saveHTML();
        $output = preg_replace('/<\?xml[^>]+>\n?/', '', $output);

        // Step 3: Restore all protected blocks in their original positions
        return $this->restoreProtected($output ?: $safe, $protected);
    }

    /**
     * Replace <script>, <style>, <svg>, <pre>, <code>, <template>, <math>,
     * and <noscript> blocks with indexed placeholders.
     * Returns [sanitized HTML, [index => original block]].
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function extractProtected(string $html): array
    {
        $protected = [];
        $index = 0;

        $sanitized = preg_replace_callback(
            self::PROTECT_PATTERN,
            function (array $m) use (&$protected, &$index): string {
                $placeholder = '<!--LINGUA_BLOCK_'.$index.'-->';
                $protected[$index] = $m[0];
                $index++;

                return $placeholder;
            },
            $html
        );

        return [$sanitized ?? $html, $protected];
    }

    /**
     * Substitute placeholders back with their original blocks.
     *
     * @param  array<int, string>  $protected
     */
    private function restoreProtected(string $html, array $protected): string
    {
        foreach ($protected as $i => $original) {
            $html = str_replace('<!--LINGUA_BLOCK_'.$i.'-->', $original, $html);
        }

        return $html;
    }

    private function isInsideTranslatableTag(DOMNode $node): bool
    {
        // Allow the text inside <title> even though its parent <head> is
        // otherwise a skip ancestor — the browser tab title is user-visible.
        $parent = $node->parentNode;
        if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'title') {
            return trim($node->nodeValue) !== '';
        }

        $el = $parent;

        while ($el instanceof \DOMElement) {
            $tag = strtolower($el->nodeName);

            // Never translate text inside non-rendered structural tags
            if (in_array($tag, self::SKIP_PARENT_TAGS, true)) {
                return false;
            }

            if (! $this->elementIsTranslatable($el)) {
                return false;
            }

            $el = $el->parentNode;
        }

        return trim($node->nodeValue) !== '';
    }

    /**
     * Check the explicit opt-outs a page author can use to keep a subtree
     * out of the translation layer without editing controllers or views.
     */
    private function elementIsTranslatable(\DOMElement $el): bool
    {
        // Respect HTML translate="no" (standard)
        if ($el->getAttribute('translate') === 'no') {
            return false;
        }

        // Respect class="notranslate" (Google Translate convention) and
        // class="lingua-skip" (our convention — both work interchangeably)
        $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
        if (array_intersect($classes, ['notranslate', 'lingua-skip'])) {
            return false;
        }

        // Respect data-lingua="skip" (opt-out via data attribute)
        if ($el->getAttribute('data-lingua') === 'skip') {
            return false;
        }

        // User-configured skip_selectors (config('lingua.skip_selectors'))
        if (! empty($this->skipSelectors) && $this->matchesAnySkipSelector($el, $classes)) {
            return false;
        }

        return true;
    }

    /**
     * Parse a list of CSS-like selectors into a normalized matcher form.
     * Supports the documented subset only — no descendant combinators or
     * pseudo-classes; selectors apply per-element.
     *
     * @param  array<int,string>  $selectors
     * @return array<int,array{tag:?string,class:?string,id:?string,attr:?string,attrValue:?string}>
     */
    private function parseSkipSelectors(array $selectors): array
    {
        $out = [];
        foreach ($selectors as $sel) {
            $sel = trim((string) $sel);
            if ($sel === '') {
                continue;
            }

            $entry = ['tag' => null, 'class' => null, 'id' => null, 'attr' => null, 'attrValue' => null];

            // Attribute: [name] or [name="value"] or [name='value']
            if (preg_match('/\[\s*([a-zA-Z_:][\w:.-]*)\s*(?:=\s*"([^"]*)"|=\s*\'([^\']*)\')?\s*\]/', $sel, $m)) {
                $entry['attr'] = strtolower($m[1]);
                $entry['attrValue'] = $m[2] ?? ($m[3] ?? null);
                $sel = preg_replace('/\[[^\]]*\]/', '', $sel);
            }

            if (preg_match('/^([a-zA-Z][\w-]*)/', $sel, $m)) {
                $entry['tag'] = strtolower($m[1]);
            }
            if (preg_match('/#([\w-]+)/', $sel, $m)) {
                $entry['id'] = $m[1];
            }
            if (preg_match('/\.([\w-]+)/', $sel, $m)) {
                $entry['class'] = $m[1];
            }

            if ($entry['tag'] || $entry['class'] || $entry['id'] || $entry['attr']) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $classes  classes already split for $el (avoids re-parsing)
     */
    private function matchesAnySkipSelector(\DOMElement $el, array $classes): bool
    {
        $tag = strtolower($el->nodeName);
        $id = $el->getAttribute('id');

        foreach ($this->skipSelectors as $sel) {
            if ($sel['tag'] !== null && $sel['tag'] !== $tag) {
                continue;
            }
            if ($sel['id'] !== null && $sel['id'] !== $id) {
                continue;
            }
            if ($sel['class'] !== null && ! in_array($sel['class'], $classes, true)) {
                continue;
            }
            if ($sel['attr'] !== null) {
                if (! $el->hasAttribute($sel['attr'])) {
                    continue;
                }
                if ($sel['attrValue'] !== null && $el->getAttribute($sel['attr']) !== $sel['attrValue']) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }
}

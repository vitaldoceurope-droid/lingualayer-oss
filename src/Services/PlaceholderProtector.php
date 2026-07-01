<?php

namespace LinguaLayer\Services;

/**
 * Masks the substrings of a text that must NEVER be translated — Laravel
 * `:placeholders`, `{curly}` variables, `%s`/`%d` printf tokens, `@mentions`,
 * `#hashtags`, inline URLs and emails, configured brand terms, and any
 * host-supplied custom patterns — replacing each with an opaque sentinel of
 * the form ⟦#N⟧ before the text is handed to the LLM, then restoring the
 * originals verbatim afterwards.
 *
 * Why mask instead of "instruct the model nicely":
 *   A prompt rule ("preserve variables") is best-effort — LLMs still
 *   occasionally translate `:name` to `:nombre` or drop `{count}`, which
 *   corrupts the host app at runtime. Masking makes preservation a
 *   *guarantee*: the model only ever sees ⟦#N⟧ and we put the real token
 *   back ourselves. If the model loses a sentinel, the caller can detect it
 *   via allTokensPresent() and fall back to the untranslated source.
 *
 * The sentinel uses the same ⟦…⟧ private brackets as the batch delimiters so
 * the system prompt's "copy anything between ⟦ and ⟧ verbatim" rule protects
 * it too, and so it never collides with ordinary content.
 */
class PlaceholderProtector
{
    /**
     * @param  array<int,string>  $brandTerms  Words that must stay verbatim.
     * @param  array<int,string>  $customPatterns  Extra PCRE regexes to preserve.
     */
    public function __construct(
        private array $brandTerms = [],
        private array $customPatterns = [],
    ) {}

    /**
     * Built-in patterns, ordered roughly longest/most-specific first. Overlap
     * resolution (longest match at a given start wins) makes the order a hint,
     * not a hard requirement — e.g. an inline URL swallows the `:` that would
     * otherwise look like a Laravel placeholder.
     *
     * @return array<int,string>
     */
    private function defaultPatterns(): array
    {
        return [
            // Inline URLs and emails (must win over the colon/at-sign rules).
            '#\bhttps?://[^\s<>"\'\)]+#i',
            '#\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b#',
            // Currency amounts — keep prices verbatim so the model can't
            // re-localise the separators (e.g. 1.299,50 € → 1 299,50 €) or alter
            // the value. Anchored on a currency symbol (\p{Sc}: € $ £ ¥ …) on
            // either side of the number, so bare counts, years and version
            // strings like "1.4.0" stay translatable and untouched.
            '/(?<![\w])(?:\p{Sc}\h?\d(?:[\d.,\x{00A0}\h]*\d)?|\d(?:[\d.,\x{00A0}\h]*\d)?\h?\p{Sc})/u',
            // Mustache / Blade-echo / Vue / Handlebars: {{ var }}
            '/\{\{[^{}]*\}\}/',
            // ICU / single-brace variables: {count}, {0}, {name}
            '/\{[^{}]*\}/',
            // printf / sprintf: %s %d %1$s %.2f %% … The trailing (?![A-Za-z])
            // stops "%descuento" or "% completado" from being mistaken for a
            // specifier while still catching real "%d puntos".
            '/%%|%(?:\d+\$)?[-+0#]?\d*(?:\.\d+)?[bcdeEfFgGosuxX](?![A-Za-z])/',
            // Laravel translation placeholders: :name :count (not after a word
            // char or another colon, so URLs and "12:30" are left alone).
            '/(?<![\w:]):[A-Za-z_][A-Za-z0-9_]*/',
            // @mentions / Blade-ish @directive leftovers
            '/(?<![\w])@[A-Za-z][\w.-]*/',
            // #hashtags (letter-initial, so "#1" stays translatable)
            '/(?<![\w])#[A-Za-z][\w-]*/u',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function brandPatterns(): array
    {
        $out = [];
        foreach ($this->brandTerms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            // Case-insensitive, boundary-aware. The original (matched) casing is
            // preserved on restore, so we never silently "correct" the brand.
            $out[] = '/(?<![\w])'.preg_quote($term, '/').'(?![\w])/iu';
        }

        return $out;
    }

    /**
     * Replace every protectable substring with a ⟦#N⟧ sentinel.
     *
     * @return array{0:string,1:array<int,string>} [masked text, id => original]
     */
    public function mask(string $text): array
    {
        if ($text === '') {
            return ['', []];
        }

        $patterns = array_merge(
            $this->defaultPatterns(),
            $this->brandPatterns(),
            $this->customPatterns,
        );

        // Collect every match with its byte offset across all patterns.
        $hits = [];
        foreach ($patterns as $re) {
            if (@preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE) && ! empty($m[0])) {
                foreach ($m[0] as $hit) {
                    [$str, $off] = $hit;
                    if ($str === '' || $off < 0) {
                        continue;
                    }
                    $hits[] = ['start' => $off, 'end' => $off + strlen($str), 'str' => $str];
                }
            }
        }

        if (empty($hits)) {
            return [$text, []];
        }

        // Sort by start; on ties prefer the longer match. Then sweep left to
        // right dropping any hit that overlaps one already chosen.
        usort($hits, fn ($a, $b) => $a['start'] <=> $b['start'] ?: $b['end'] <=> $a['end']);

        $map = [];
        $out = '';
        $pos = 0;
        $cursor = 0;
        $id = 0;

        foreach ($hits as $h) {
            if ($h['start'] < $cursor) {
                continue; // overlaps a token we already placed
            }
            $out .= substr($text, $pos, $h['start'] - $pos);
            $out .= $this->token($id);
            $map[$id] = $h['str'];
            $pos = $h['end'];
            $cursor = $h['end'];
            $id++;
        }
        $out .= substr($text, $pos);

        return [$out, $map];
    }

    /**
     * Put the original tokens back. Tolerant of incidental whitespace the model
     * may inject inside a sentinel (⟦# 0 ⟧). Unknown ids are left as-is.
     *
     * @param  array<int,string>  $map
     */
    public function restore(string $text, array $map): string
    {
        if (empty($map)) {
            return $text;
        }

        return preg_replace_callback(
            '/⟦#\s*(\d+)\s*⟧/u',
            fn ($m) => $map[(int) $m[1]] ?? $m[0],
            $text,
        ) ?? $text;
    }

    /**
     * True when every sentinel we injected is still present in the model's
     * output. A false result means the model dropped or mangled a placeholder
     * and the translation must not be trusted for that fragment.
     *
     * @param  array<int,string>  $map
     */
    public function allTokensPresent(string $text, array $map): bool
    {
        foreach (array_keys($map) as $id) {
            if (! preg_match('/⟦#\s*'.$id.'\s*⟧/u', $text)) {
                return false;
            }
        }

        return true;
    }

    private function token(int $id): string
    {
        return '⟦#'.$id.'⟧';
    }
}

<?php

namespace LinguaLayer\Services;

use LinguaLayer\Contracts\TranslatorInterface;

/**
 * Transparent decorator that guarantees placeholders, variables and brand
 * terms survive translation, regardless of the underlying driver (Gemini or
 * Gateway) and regardless of direction (output source→target or form input
 * target→source).
 *
 * For each text it:
 *   1. masks the protectable substrings with ⟦#N⟧ sentinels,
 *   2. delegates the masked text to the wrapped translator,
 *   3. restores the original tokens verbatim,
 *   4. if the driver lost a sentinel, falls back to the untranslated source
 *      for that fragment — a coherent original always beats a broken variable.
 *
 * It preserves the exact TranslatorInterface contract: same keys back, null on
 * atomic batch failure, getName()/isConfigured() pass straight through so
 * dashboards and mode detection are unaffected.
 */
class PreservingTranslator implements TranslatorInterface
{
    public function __construct(
        private TranslatorInterface $inner,
        private PlaceholderProtector $protector,
    ) {}

    public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
    {
        [$masked, $map] = $this->protector->mask($text);

        $result = $this->inner->translate($masked, $target, $source, $context);
        if ($result === null) {
            return null;
        }

        if (! $this->protector->allTokensPresent($result, $map)) {
            return $text; // driver dropped a placeholder — keep the source intact
        }

        return $this->protector->restore($result, $map);
    }

    public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
    {
        if (empty($texts)) {
            return $this->inner->translateBatch($texts, $target, $source, $context);
        }

        // Mask while remembering the caller's original keys and source texts.
        $keys = array_keys($texts);
        $values = array_values($texts);
        $maskedSeq = [];
        $maps = [];

        foreach ($values as $i => $value) {
            [$masked, $map] = $this->protector->mask((string) $value);
            $maskedSeq[$i] = $masked;
            $maps[$i] = $map;
        }

        $result = $this->inner->translateBatch($maskedSeq, $target, $source, $context);
        if ($result === null) {
            return null; // preserve the atomic-failure contract
        }

        // The interface guarantees results keyed by input position (0..n-1).
        $out = [];
        foreach ($keys as $i => $originalKey) {
            $original = (string) $values[$i];
            $translated = $result[$i] ?? null;

            if ($translated === null) {
                // Driver left this slot empty (e.g. a partially-failed Gateway
                // chunk) — surface the source rather than a hole.
                $out[$originalKey] = $original;

                continue;
            }

            if (! $this->protector->allTokensPresent((string) $translated, $maps[$i])) {
                $out[$originalKey] = $original;

                continue;
            }

            $out[$originalKey] = $this->protector->restore((string) $translated, $maps[$i]);
        }

        return $out;
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    /** Escape hatch for callers that need the concrete driver (dashboards, tests). */
    public function inner(): TranslatorInterface
    {
        return $this->inner;
    }
}

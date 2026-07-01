<?php

namespace LinguaLayer\Services;

use LinguaLayer\Contracts\TranslatorInterface;

/**
 * Safe no-op translator used when LinguaLayer is installed but not yet
 * configured (no provider key / no license). Every translate call returns
 * null, so HtmlTranslator serves the original text and the host site keeps
 * working — instead of the container throwing during middleware construction
 * and 500-ing every request. This upholds the fail-safe guarantee: an
 * unconfigured install must never break the host.
 */
class NullTranslator implements TranslatorInterface
{
    public function translate(
        string $text,
        string $target,
        ?string $source = null,
        ?string $context = null
    ): ?string {
        return null;
    }

    public function translateBatch(
        array $texts,
        string $target,
        ?string $source = null,
        ?string $context = null
    ): ?array {
        return null;
    }

    public function getName(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }
}

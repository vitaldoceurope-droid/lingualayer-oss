<?php

namespace LinguaLayer\Contracts;

/**
 * Provider-neutral translator. Both the local Gemini client and the remote
 * Gateway client implement this — the host app talks only to the interface,
 * never to a concrete driver.
 */
interface TranslatorInterface
{
    /**
     * Translate a single string. Returns null on hard failure (the caller
     * should fall back to the source). Implementations must NEVER throw on
     * recoverable errors — those become a null return.
     */
    public function translate(
        string $text,
        string $target,
        ?string $source = null,
        ?string $context = null
    ): ?string;

    /**
     * Batch variant. Returns an array indexed by the original input position
     * with translated values, or null if the entire batch failed atomically.
     *
     * @param  string[]  $texts
     * @return array<int,string>|null
     */
    public function translateBatch(
        array $texts,
        string $target,
        ?string $source = null,
        ?string $context = null
    ): ?array;

    /** Driver/mode name for logs and dashboards. */
    public function getName(): string;

    /** True when the driver has the credentials it needs. */
    public function isConfigured(): bool;
}

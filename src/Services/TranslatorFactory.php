<?php

namespace LinguaLayer\Services;

use LinguaLayer\Contracts\TranslatorInterface;
use RuntimeException;

/**
 * Picks a TranslatorInterface implementation based on env config.
 *
 *   LINGUA_MODE=standalone → local provider (LINGUA_PROVIDER: gemini | openai)
 *   LINGUA_MODE=gateway    → GatewayClient    (managed service)
 *   LINGUA_MODE=auto       → gateway if a license key is present, else standalone
 *
 * In standalone mode LINGUA_PROVIDER selects the driver: 'gemini' (default) or
 * 'openai' (any OpenAI-compatible endpoint, incl. self-hosted). Whichever driver
 * is chosen is wrapped in PreservingTranslator. Throws when nothing is
 * configured. Callers can pre-check with detectMode().
 */
class TranslatorFactory
{
    public static function detectMode(): string
    {
        $mode = (string) config('lingua.mode', 'auto');

        if ($mode === 'standalone') {
            return self::hasStandaloneKey() ? 'standalone' : 'unconfigured';
        }
        if ($mode === 'gateway') {
            return self::hasGatewayKey() ? 'gateway' : 'unconfigured';
        }

        // auto
        if (self::hasGatewayKey()) {
            return 'gateway';
        }
        if (self::hasStandaloneKey()) {
            return 'standalone';
        }

        return 'unconfigured';
    }

    public static function make(): TranslatorInterface
    {
        $mode = self::detectMode();

        $driver = match ($mode) {
            'standalone' => self::makeStandalone(),
            'gateway' => self::makeGateway(),
            default => throw new RuntimeException(
                'LinguaLayer is not configured. For standalone mode set a provider key '
                .'(LINGUA_GEMINI_KEY, or LINGUA_OPENAI_KEY with LINGUA_PROVIDER=openai); '
                .'for managed mode set LINGUA_LICENSE_KEY. '
                .'Run `php artisan lingua:configure` for an interactive setup.'
            ),
        };

        return self::withPreservation($driver);
    }

    /**
     * Wrap a driver so placeholders, variables and brand terms are masked
     * before they reach the LLM and restored afterwards — for every path
     * (HTML output, dynamic DOM, form input) and both drivers. Opt out with
     * LINGUA_PRESERVE=false.
     */
    private static function withPreservation(TranslatorInterface $driver): TranslatorInterface
    {
        if (! config('lingua.translation.preserve_enabled', true)) {
            return $driver;
        }

        return new PreservingTranslator($driver, new PlaceholderProtector(
            (array) config('lingua.translation.brand_terms', []),
            (array) config('lingua.translation.preserve_patterns', []),
        ));
    }

    /** The configured standalone LLM provider. */
    private static function provider(): string
    {
        return strtolower((string) config('lingua.provider', 'gemini'));
    }

    private static function hasStandaloneKey(): bool
    {
        return match (self::provider()) {
            'openai' => (string) config('lingua.openai.api_key', '') !== ''
                || rtrim((string) config('lingua.openai.base_url', 'https://api.openai.com/v1'), '/') !== 'https://api.openai.com/v1',
            default => (string) config('lingua.gemini_api_key', '') !== '',
        };
    }

    private static function hasGatewayKey(): bool
    {
        return (string) config('lingua.gateway.license_key', '') !== '';
    }

    /** Build the standalone driver for the configured provider. */
    private static function makeStandalone(): TranslatorInterface
    {
        $cache = app(TranslationCache::class);

        return match (self::provider()) {
            'openai' => new OpenAiTranslator($cache),
            default => new GeminiTranslator($cache),
        };
    }

    private static function makeGateway(): GatewayClient
    {
        return new GatewayClient(
            baseUrl: rtrim((string) config('lingua.gateway.url', ''), '/'),
            licenseKey: (string) config('lingua.gateway.license_key', ''),
            timeout: (int) config('lingua.gateway.timeout', 30),
            verifySsl: (bool) config('lingua.gateway.verify_ssl', true),
        );
    }
}

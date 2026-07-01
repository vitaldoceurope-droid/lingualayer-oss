<?php

use LinguaLayer\Services\GatewayClient;

/**
 * The version a client's browser sees must match the package version. In
 * gateway mode lingua.js is loaded centrally and cache-busted by
 * GatewayClient::PACKAGE_VERSION (or the gateway's advertised js_version), so a
 * drift means a client can load a stale build whose window.__linguaVersion lies
 * about what is actually running. Keep the shipped JS files in lockstep with
 * the PHP constant.
 */
function linguaJsVersion(string $path): ?string
{
    if (! is_file($path)) {
        return null;
    }
    $src = (string) file_get_contents($path);

    return preg_match("/window\\.__linguaVersion\\s*=\\s*'([^']+)'/", $src, $m) ? $m[1] : null;
}

test('resources/js/lingua.js __linguaVersion equals PACKAGE_VERSION', function () {
    $path = dirname(__DIR__, 2).'/resources/js/lingua.js';
    expect(linguaJsVersion($path))->toBe(GatewayClient::PACKAGE_VERSION);
});

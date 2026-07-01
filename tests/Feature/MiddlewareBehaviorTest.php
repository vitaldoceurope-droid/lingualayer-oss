<?php

use Illuminate\Support\Facades\Route;

/**
 * These tests guard the zero-touch install contract: the middleware must
 * never alter responses a client did not ask us to touch.
 */
beforeEach(function () {
    config()->set('lingua.excluded_routes', ['admin/*', 'api/*', 'lingua/*']);

    // Register a few routes to simulate a real client app
    Route::middleware(['web'])->get('/admin/dashboard', fn () => '<html><head><title>Admin</title></head><body><h1>Hola</h1></body></html>');
    Route::middleware(['web'])->get('/public/home', fn () => '<html><head><title>Public</title></head><body><h1>Hola</h1></body></html>');
    Route::middleware(['web'])->get('/json-endpoint', fn () => response()->json(['ok' => true]));
});

test('excluded routes are passed through untouched', function () {
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/admin/dashboard');

    expect($response->getContent())->not->toContain('lingua-config');
    expect($response->getContent())->not->toContain('vendor/lingualayer/lingua.js');
});

test('json responses are never modified', function () {
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')
        ->get('/json-endpoint');

    expect($response->getContent())->toBe(json_encode(['ok' => true]));
});

test('source language pages still get the selector UI injected', function () {
    // No cookie set — user is on source language
    $response = $this->get('/public/home');

    expect($response->getContent())->toContain('lingua-config');
    expect($response->getContent())->toContain('vendor/lingualayer/lingua.js');
});

test('injector falls back when </head> is missing', function () {
    Route::middleware(['web'])->get('/no-head', fn () => '<html><body><h1>Hola</h1></body></html>');

    $response = $this->get('/no-head');

    expect($response->getContent())->toContain('lingua-config');
});

test('served pages carry a revalidate Cache-Control so browsers never show stale HTML', function () {
    $response = $this->get('/public/home');

    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
});

test('excluded routes do NOT get our Cache-Control header', function () {
    $response = $this->withUnencryptedCookie('lingua_lang', 'en')->get('/admin/dashboard');

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('must-revalidate');
});

test('browser cache header is configurable', function () {
    config()->set('lingua.browser_cache_control', 'public, max-age=120');

    $response = $this->get('/public/home');

    expect($response->headers->get('Cache-Control'))->toContain('max-age=120');
});

test('browser cache header can be turned off', function () {
    config()->set('lingua.browser_cache_control', '');

    $response = $this->get('/public/home');

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('must-revalidate');
});

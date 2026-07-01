<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LinguaLayer\Services\GatewayClient;

/**
 * Guards transparent batch chunking. The Gateway validates `texts.max=100`
 * per request, so the client must split larger inputs into MAX_BATCH_SIZE
 * chunks, dispatch sequentially, and recombine while preserving keys + order.
 */
beforeEach(function () {
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_gateway_license_valid');
});

test('translateBatch with 50 items sends exactly 1 request', function () {
    Http::fake(['*api/v1/translate-batch' => Http::response([
        'translations' => array_fill(0, 50, 'OK'),
        'cached_count' => 0, 'api_count' => 50,
    ], 200)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 50, 'hola'), 'fr', 'es');

    expect($out)->toHaveCount(50);
    Http::assertSentCount(1);
});

test('translateBatch with exactly 100 items sends exactly 1 request', function () {
    Http::fake(['*api/v1/translate-batch' => Http::response([
        'translations' => array_fill(0, 100, 'OK'),
        'cached_count' => 0, 'api_count' => 100,
    ], 200)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 100, 'hola'), 'fr', 'es');

    expect($out)->toHaveCount(100);
    Http::assertSentCount(1);
});

test('translateBatch with 101 items sends 2 requests (100 + 1)', function () {
    Http::fake(['*api/v1/translate-batch' => Http::sequence()
        ->push(['translations' => array_fill(0, 100, 'A'), 'cached_count' => 0, 'api_count' => 100], 200)
        ->push(['translations' => ['B'], 'cached_count' => 0, 'api_count' => 1], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 101, 'hola'), 'fr', 'es');

    expect($out)->toHaveCount(101);
    Http::assertSentCount(2);

    $sent = Http::recorded();
    expect(count($sent[0][0]->data()['texts']))->toBe(100);
    expect(count($sent[1][0]->data()['texts']))->toBe(1);
});

test('translateBatch with 250 items sends 3 requests (100, 100, 50)', function () {
    Http::fake(['*api/v1/translate-batch' => Http::sequence()
        ->push(['translations' => array_fill(0, 100, 'A'), 'cached_count' => 0, 'api_count' => 100], 200)
        ->push(['translations' => array_fill(0, 100, 'B'), 'cached_count' => 0, 'api_count' => 100], 200)
        ->push(['translations' => array_fill(0, 50, 'C'), 'cached_count' => 0, 'api_count' => 50], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 250, 'hola'), 'fr', 'es');

    expect($out)->toHaveCount(250);
    Http::assertSentCount(3);

    $sent = Http::recorded();
    expect(count($sent[0][0]->data()['texts']))->toBe(100);
    expect(count($sent[1][0]->data()['texts']))->toBe(100);
    expect(count($sent[2][0]->data()['texts']))->toBe(50);
});

test('translateBatch preserves order across chunks', function () {
    $inputs = [];
    for ($i = 0; $i < 150; $i++) {
        $inputs[] = "src-$i";
    }

    Http::fake(['*api/v1/translate-batch' => Http::sequence()
        ->push(['translations' => array_map(fn ($i) => "T-$i", range(0, 99))], 200)
        ->push(['translations' => array_map(fn ($i) => "T-$i", range(100, 149))], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch($inputs, 'fr', 'es');

    expect($out[0])->toBe('T-0');
    expect($out[99])->toBe('T-99');
    expect($out[100])->toBe('T-100');
    expect($out[149])->toBe('T-149');
});

test('translateBatch preserves original keys after chunking', function () {
    $inputs = [];
    for ($i = 0; $i < 120; $i++) {
        $inputs["key_$i"] = "src-$i";
    }

    Http::fake(['*api/v1/translate-batch' => Http::sequence()
        ->push(['translations' => array_map(fn ($i) => "T-$i", range(0, 99))], 200)
        ->push(['translations' => array_map(fn ($i) => "T-$i", range(100, 119))], 200),
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch($inputs, 'fr', 'es');

    expect(array_keys($out))->toBe(array_keys($inputs));
    expect($out['key_0'])->toBe('T-0');
    expect($out['key_119'])->toBe('T-119');
});

test('translateBatch returns partial results when one chunk fails', function () {
    // FIX 2026-04-27: previously this test asserted null on partial failure.
    // That contract caused real-world data loss — a single Gateway timeout
    // dropped the entire batch even though other chunks succeeded. Now we
    // keep the good chunks and null-fill only the failed ones.
    Http::fake(['*api/v1/translate-batch' => Http::sequence()
        ->push(['translations' => array_fill(0, 100, 'A')], 200) // chunk 1 ok
        ->push(['error' => 'quota_exceeded'], 429),               // chunk 2 fails
    ]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 150, 'hola'), 'fr', 'es');

    expect($out)->toHaveCount(150);
    // First 100 succeeded
    expect(array_slice($out, 0, 100, true))->each->toBe('A');
    // Last 50 are null (the failed chunk)
    expect(array_slice($out, 100, 50, true))->each->toBeNull();
});

test('translateBatch returns null only when every chunk fails', function () {
    Http::fake(['*api/v1/translate-batch' => Http::response(['error' => 'down'], 503)]);

    $client = new GatewayClient('http://localhost:8001', 'LL-K', 5, false);
    $out = $client->translateBatch(array_fill(0, 150, 'hola'), 'fr', 'es');

    expect($out)->toBeNull();
});

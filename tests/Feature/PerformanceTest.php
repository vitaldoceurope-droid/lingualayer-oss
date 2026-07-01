<?php

use LinguaLayer\Services\GeminiTranslator;
use LinguaLayer\Services\HtmlTranslator;
use LinguaLayer\Services\TranslationCache;

test('HTML with 500 nodes processes in under 2 seconds', function () {
    $items = str_repeat('<li>Elemento de lista común</li>', 500);
    $html = "<html><body><ul>{$items}</ul></body></html>";

    $mock = Mockery::mock(GeminiTranslator::class);
    $mock->shouldReceive('translateBatch')->andReturn([0 => 'ITEM']);

    $start = microtime(true);
    $result = (new HtmlTranslator($mock))->translate($html, 'en');
    $elapsed = microtime(true) - $start;

    expect($result)->toContain('ITEM');
    expect($elapsed)->toBeLessThan(2.0);
});

test('cache hit responds in under 50 ms', function () {
    $cache = app(TranslationCache::class);
    for ($i = 0; $i < 100; $i++) {
        $cache->set("texto {$i}", 'en', "text {$i}");
    }

    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $cache->get("texto {$i}", 'en');
    }
    $elapsed = (microtime(true) - $start) * 1000;

    expect($elapsed)->toBeLessThan(50.0);
});

test('1000 cache writes complete in under 1 second', function () {
    $cache = app(TranslationCache::class);
    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $cache->set("k{$i}", 'en', "v{$i}");
    }
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(1.0);
});

test('memory peak with 200 unique fragments stays bounded', function () {
    gc_collect_cycles();
    $start = memory_get_usage(true);

    $cache = app(TranslationCache::class);
    for ($i = 0; $i < 200; $i++) {
        $cache->set("texto unico numero {$i}", 'en', "unique text number {$i}");
    }

    $peak = memory_get_peak_usage(true);
    $delta = $peak - $start;

    // Bounded at 50 MB delta — generous for the array driver
    expect($delta)->toBeLessThan(50 * 1024 * 1024);
});

test('JSON response for 100 translations encodes in under 100 ms', function () {
    $items = [];
    for ($i = 0; $i < 100; $i++) {
        $items[] = ['source' => "fuente {$i}", 'translated' => "translated {$i}"];
    }

    $start = microtime(true);
    $json = json_encode($items, JSON_UNESCAPED_UNICODE);
    $elapsed = (microtime(true) - $start) * 1000;

    expect($json)->toBeString();
    expect($elapsed)->toBeLessThan(100.0);
});

test('TranslationCache::set is idempotent for same key (no perf regression)', function () {
    $cache = app(TranslationCache::class);
    $start = microtime(true);
    for ($i = 0; $i < 500; $i++) {
        $cache->set('repetido', 'en', "value {$i}");
    }
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(2.0);
    expect($cache->get('repetido', 'en'))->toBe('value 499');
});

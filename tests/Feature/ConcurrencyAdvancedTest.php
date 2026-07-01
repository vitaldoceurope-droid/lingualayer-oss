<?php

use Illuminate\Support\Facades\Bus;
use LinguaLayer\Jobs\TranslatePageJob;
use LinguaLayer\Services\TranslationCache;

test('cache write idempotency: counter reflects unique fragments only', function () {
    $cache = app(TranslationCache::class);

    $cache->set('texto unico', 'en', 'unique text');
    $first = TranslationCache::readStat(TranslationCache::STATS_FRAGMENTS_TOTAL);

    $cache->set('texto unico', 'en', 'updated text');
    $cache->set('texto unico', 'en', 'updated again');
    $third = TranslationCache::readStat(TranslationCache::STATS_FRAGMENTS_TOTAL);

    expect($third)->toBe($first);
    expect($cache->get('texto unico', 'en'))->toBe('updated again');
});

test('TranslatePageJob can be dispatched twice without crashing', function () {
    Bus::fake();

    TranslatePageJob::dispatch('<html><body><p>Hola</p></body></html>', 'en', 'lingua_page_t');
    TranslatePageJob::dispatch('<html><body><p>Hola</p></body></html>', 'en', 'lingua_page_t');

    Bus::assertDispatchedTimes(TranslatePageJob::class, 2);
});

test('reading and writing the same cache key in alternation is consistent', function () {
    $cache = app(TranslationCache::class);
    $cache->set('alternar', 'en', 'value-1');

    expect($cache->get('alternar', 'en'))->toBe('value-1');
    $cache->set('alternar', 'en', 'value-2');
    expect($cache->get('alternar', 'en'))->toBe('value-2');
    $cache->set('alternar', 'en', 'value-3');
    expect($cache->get('alternar', 'en'))->toBe('value-3');
});

test('100 sequential set/get operations on different keys stay consistent', function () {
    $cache = app(TranslationCache::class);

    for ($i = 0; $i < 100; $i++) {
        $cache->set("k{$i}", 'en', "v{$i}");
    }

    $miscount = 0;
    for ($i = 0; $i < 100; $i++) {
        if ($cache->get("k{$i}", 'en') !== "v{$i}") {
            $miscount++;
        }
    }

    expect($miscount)->toBe(0);
});

test('forget then re-set produces fresh entry', function () {
    $cache = app(TranslationCache::class);
    $cache->set('volátil', 'en', 'first');
    $cache->forget('volátil', 'en');
    expect($cache->get('volátil', 'en'))->toBeNull();
    $cache->set('volátil', 'en', 'second');
    expect($cache->get('volátil', 'en'))->toBe('second');
});

test('cache key separator prevents lang/text boundary collisions', function () {
    $cache = app(TranslationCache::class);

    $cache->set('ab', 'cen', 'AB→CEN');
    $cache->set('abc', 'en', 'ABC→EN');

    expect($cache->get('ab', 'cen'))->toBe('AB→CEN');
    expect($cache->get('abc', 'en'))->toBe('ABC→EN');
});

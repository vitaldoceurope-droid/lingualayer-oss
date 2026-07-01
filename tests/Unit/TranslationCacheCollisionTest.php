<?php

use LinguaLayer\Services\TranslationCache;

test('hash does not collide between text and language boundaries', function () {
    $cache = app(TranslationCache::class);

    // Prior to the fix, md5("ab" . "cen") == md5("abc" . "en") would share a key.
    $cache->set('ab', 'cen', 'First');
    $cache->set('abc', 'en', 'Second');

    expect($cache->get('ab', 'cen'))->toBe('First');
    expect($cache->get('abc', 'en'))->toBe('Second');
});

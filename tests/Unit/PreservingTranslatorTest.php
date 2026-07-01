<?php

use LinguaLayer\Contracts\TranslatorInterface;
use LinguaLayer\Services\PlaceholderProtector;
use LinguaLayer\Services\PreservingTranslator;

/**
 * A driver whose behaviour is a closure over the (already-masked) input, so
 * each test can simulate a well-behaved LLM, a token-dropping one, or a hard
 * failure.
 */
function fakeDriver(callable $batch, string $name = 'fake'): TranslatorInterface
{
    return new class($batch, $name) implements TranslatorInterface
    {
        public function __construct(private $batch, private string $name) {}

        public function translate(string $text, string $target, ?string $source = null, ?string $context = null): ?string
        {
            $r = ($this->batch)([$text], $target);

            return $r === null ? null : ($r[0] ?? null);
        }

        public function translateBatch(array $texts, string $target, ?string $source = null, ?string $context = null): ?array
        {
            return ($this->batch)(array_values($texts), $target);
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };
}

test('restores placeholders after the driver translates the masked text', function () {
    // Driver keeps the ⟦#N⟧ sentinels intact (a well-behaved model).
    $driver = fakeDriver(fn (array $texts) => array_map(fn ($t) => 'EN:'.$t, $texts));
    $wrapped = new PreservingTranslator($driver, new PlaceholderProtector);

    $out = $wrapped->translateBatch(['Hola :name'], 'en');

    expect($out[0])->toBe('EN:Hola :name');
});

test('falls back to the source text when the driver drops a placeholder', function () {
    $driver = fakeDriver(fn (array $texts) => array_map(
        fn ($t) => preg_replace('/⟦#\d+⟧/u', '', $t),
        $texts,
    ));
    $wrapped = new PreservingTranslator($driver, new PlaceholderProtector);

    $out = $wrapped->translateBatch(['Hola :name'], 'en');

    expect($out[0])->toBe('Hola :name'); // not a broken "Hola "
});

test('propagates atomic failure (null) from the driver', function () {
    $driver = fakeDriver(fn () => null);
    $wrapped = new PreservingTranslator($driver, new PlaceholderProtector);

    expect($wrapped->translateBatch(['Hola :name'], 'en'))->toBeNull();
});

test('keeps configured brand terms verbatim end to end', function () {
    $driver = fakeDriver(fn (array $texts) => array_map(fn ($t) => 'EN:'.$t, $texts));
    $wrapped = new PreservingTranslator($driver, new PlaceholderProtector(['ViataLing']));

    $out = $wrapped->translateBatch(['Usa ViataLing ahora'], 'en');

    expect($out[0])->toBe('EN:Usa ViataLing ahora');
});

test('preserves caller keys and passes getName/isConfigured straight through', function () {
    $driver = fakeDriver(fn (array $texts) => array_map('strtoupper', $texts), 'gemini-direct');
    $wrapped = new PreservingTranslator($driver, new PlaceholderProtector);

    $out = $wrapped->translateBatch([0 => 'hola', 1 => 'mundo'], 'en');

    expect($out)->toBe([0 => 'HOLA', 1 => 'MUNDO'])
        ->and($wrapped->getName())->toBe('gemini-direct')
        ->and($wrapped->isConfigured())->toBeTrue();
});

test('single translate() masks/restores, and recovers from a dropped token', function () {
    $keep = fakeDriver(fn (array $texts) => array_map(fn ($t) => 'EN:'.$t, $texts));
    $drop = fakeDriver(fn (array $texts) => array_map(
        fn ($t) => preg_replace('/⟦#\d+⟧/u', 'X', $t),
        $texts,
    ));

    expect((new PreservingTranslator($keep, new PlaceholderProtector))->translate('Hola :name', 'en'))
        ->toBe('EN:Hola :name')
        ->and((new PreservingTranslator($drop, new PlaceholderProtector))->translate('Hola :name', 'en'))
        ->toBe('Hola :name');
});

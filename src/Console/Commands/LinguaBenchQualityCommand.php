<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use LinguaLayer\Services\GeminiTranslator;

/**
 * Offline-friendly translation benchmark. NOT part of `composer test` because
 * it makes real Gemini API calls — running it on every commit would burn
 * tokens and produce flaky results when the model output drifts. Run on
 * demand: `php artisan lingua:bench-quality --target=fr`.
 */
class LinguaBenchQualityCommand extends Command
{
    protected $signature = 'lingua:bench-quality
        {--target=fr : Target language ISO 639-1 code}
        {--threshold=0.6 : Minimum similarity (0–1) per item}
        {--limit=0 : Limit the number of items run (0 = all)}';

    protected $description = 'Run an offline translation benchmark against a fixed dataset (real Gemini calls).';

    /**
     * Source-language texts paired with reference translations per target lang.
     * Keep the dataset deliberately small — it is a smoke test, not a corpus.
     *
     * @var array<int, array{source: string, refs: array<string, string>}>
     */
    private const DATASET = [
        ['source' => 'Su cita es mañana a las diez',
            'refs' => ['fr' => 'Votre rendez-vous est demain à dix heures',
                'en' => 'Your appointment is tomorrow at ten']],

        ['source' => 'Añadir al carrito',
            'refs' => ['fr' => 'Ajouter au panier',
                'en' => 'Add to cart']],

        ['source' => 'Acepto los términos y condiciones',
            'refs' => ['fr' => "J'accepte les conditions générales",
                'en' => 'I accept the terms and conditions']],

        ['source' => 'Guardar cambios',
            'refs' => ['fr' => 'Enregistrer les modifications',
                'en' => 'Save changes']],

        ['source' => 'No se ha podido guardar el documento',
            'refs' => ['fr' => "Impossible d'enregistrer le document",
                'en' => 'Could not save the document']],

        ['source' => 'Bienvenido al panel de administración',
            'refs' => ['fr' => "Bienvenue dans le panneau d'administration",
                'en' => 'Welcome to the admin panel']],

        ['source' => 'Su sesión ha expirado, por favor inicie sesión de nuevo',
            'refs' => ['fr' => 'Votre session a expiré, veuillez vous reconnecter',
                'en' => 'Your session has expired, please log in again']],

        ['source' => 'Buscar pacientes por nombre o número de historia',
            'refs' => ['fr' => 'Rechercher des patients par nom ou numéro de dossier',
                'en' => 'Search patients by name or record number']],
    ];

    public function handle(GeminiTranslator $translator): int
    {
        $target = (string) $this->option('target');
        $threshold = (float) $this->option('threshold');
        $limit = (int) $this->option('limit');

        $items = array_filter(self::DATASET, fn ($it) => isset($it['refs'][$target]));

        if (empty($items)) {
            $this->error("No reference translations for target language '{$target}'.");

            return self::FAILURE;
        }

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        $sources = array_column($items, 'source');
        $this->line('Translating '.count($sources)." item(s) to '{$target}'…");

        $translated = $translator->translateBatch($sources, $target);
        if ($translated === null) {
            $this->error('Translation failed (all retries exhausted). Check API key and connectivity.');

            return self::FAILURE;
        }

        $rows = [];
        $passed = 0;

        foreach ($items as $i => $item) {
            $expected = $item['refs'][$target];
            $actual = (string) ($translated[$i] ?? '');
            $sim = $this->similarity($expected, $actual);
            $ok = $sim >= $threshold;

            $rows[] = [
                $item['source'],
                $expected,
                $actual,
                number_format($sim, 2),
                $ok ? 'PASS' : 'FAIL',
            ];

            if ($ok) {
                $passed++;
            }
        }

        $this->table(['Source', 'Expected', 'Actual', 'Sim', 'Result'], $rows);

        $total = count($items);
        $this->line('');
        $this->line(sprintf(
            '%d/%d items above threshold %.2f (%.1f%%)',
            $passed, $total, $threshold, ($passed / max($total, 1)) * 100
        ));

        return $passed === $total ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Token-overlap similarity: a forgiving heuristic. Translation can be
     * lexically different and still correct; this rewards shared content
     * words and punishes nothing for word order. Range 0..1.
     */
    private function similarity(string $a, string $b): float
    {
        $tokenize = function (string $s): array {
            $s = mb_strtolower($s, 'UTF-8');
            $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? '';
            $tokens = preg_split('/\s+/u', trim($s)) ?: [];

            return array_filter($tokens, fn ($t) => $t !== '');
        };

        $ta = array_unique($tokenize($a));
        $tb = array_unique($tokenize($b));

        if (empty($ta) && empty($tb)) {
            return 1.0;
        }
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }

        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union === 0 ? 0.0 : $inter / $union;
    }
}

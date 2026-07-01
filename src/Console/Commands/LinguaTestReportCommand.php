<?php

namespace LinguaLayer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Pilar 4.17 — runs the test suite and generates a self-contained HTML
 * report under storage/lingua/test-report.html. Stays usable even when
 * Xdebug/PCOV is missing — coverage just shows "n/a" in that case.
 */
class LinguaTestReportCommand extends Command
{
    protected $signature = 'lingua:test-report';

    protected $description = 'Run the test suite and generate an HTML report';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=blue;options=bold> LinguaLayer · Test report </>');
        $this->line('─────────────────────────────────────');
        $this->line('Running Pest…');

        // Detect whether we're inside the package repo or a host app that
        // installed the package via composer. The package's own tests live
        // under vendor/lingualayer/lingualayer/tests/ in a host app.
        $packageRoot = $this->detectPackageRoot();
        $hostRoot = base_path();
        $workingDir = $packageRoot ?: $hostRoot;

        $pestBinary = file_exists($workingDir.'/vendor/bin/pest')
            ? $workingDir.'/vendor/bin/pest'
            : (file_exists($hostRoot.'/vendor/bin/pest') ? $hostRoot.'/vendor/bin/pest' : null);

        if ($pestBinary === null) {
            $this->error('Could not find vendor/bin/pest. Run composer install first.');

            return self::FAILURE;
        }

        $start = microtime(true);
        $process = new Process([$pestBinary, '--colors=never']);
        $process->setWorkingDirectory($workingDir);
        $process->setTimeout(600);
        $process->run();

        $elapsed = round(microtime(true) - $start, 1);
        $output = $process->getOutput().$process->getErrorOutput();

        $passed = $this->extract($output, '/(\d+) passed/');
        $failed = $this->extract($output, '/(\d+) failed/');
        $skipped = $this->extract($output, '/(\d+) skipped/');

        $total = $passed + $failed + $skipped;
        $ready = $failed === 0 ? 'YES' : 'NO';

        $this->renderConsole($passed, $failed, $skipped, $elapsed, $ready);

        $reportDir = storage_path('lingua');
        File::ensureDirectoryExists($reportDir);
        $reportFile = $reportDir.'/test-report.html';

        File::put($reportFile, $this->renderHtml(
            passed: $passed,
            failed: $failed,
            skipped: $skipped,
            total: $total,
            elapsed: $elapsed,
            output: $output,
            ready: $ready,
        ));

        $this->line('');
        $this->line('Report written to <fg=cyan>'.$reportFile.'</>');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Look for the LinguaLayer package source. Returns null when we're
     * already inside the package repo (since base_path() == package root).
     */
    private function detectPackageRoot(): ?string
    {
        $candidate = base_path('vendor/lingualayer/lingualayer');
        if (is_dir($candidate) && is_dir($candidate.'/tests')) {
            return $candidate;
        }

        return null;
    }

    private function extract(string $output, string $pattern): int
    {
        if (preg_match($pattern, $output, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function renderConsole(int $passed, int $failed, int $skipped, float $elapsed, string $ready): void
    {
        $this->line('');
        $this->line('<fg=blue;options=bold>📊 LinguaLayer Test Report</>');
        $this->line('══════════════════════════');
        $this->line(sprintf('  ✅ Tests passed:    <fg=green;options=bold>%d</>', $passed));
        $this->line(sprintf('  ❌ Tests failed:    <fg=%s;options=bold>%d</>', $failed > 0 ? 'red' : 'green', $failed));
        $this->line(sprintf('  ⏭️  Tests skipped:   <fg=yellow>%d</>', $skipped));
        $this->line(sprintf('  ⏱️  Duration:       %.1fs', $elapsed));
        $this->line(sprintf('  🎯 Production ready: <fg=%s;options=bold>%s</>', $ready === 'YES' ? 'green' : 'red', $ready));
    }

    private function renderHtml(int $passed, int $failed, int $skipped, int $total, float $elapsed, string $output, string $ready): string
    {
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $output); // strip ANSI codes
        $rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>LinguaLayer Test Report</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:system-ui,-apple-system,sans-serif}
body{background:#f5f5f7;color:#1d1d1f;padding:32px;font-size:14px;line-height:1.55}
h1{font-size:1.6rem;margin-bottom:.25rem}
h1 span{background:linear-gradient(135deg,#4f46e5,#7c3aed);-webkit-background-clip:text;color:transparent}
p.subtitle{color:#666;font-size:.9rem;margin-bottom:24px}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.card .label{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#888}
.card .value{font-size:2rem;font-weight:800;margin-top:4px}
.good{color:#16a34a} .warn{color:#d97706} .bad{color:#dc2626}
.banner{padding:16px 24px;border-radius:10px;margin-bottom:24px;font-weight:600}
.banner.ready{background:#dcfce7;color:#16a34a;border-left:4px solid #16a34a}
.banner.notready{background:#fee2e2;color:#dc2626;border-left:4px solid #dc2626}
pre{background:#1d1d1f;color:#e5e5ea;border-radius:10px;padding:24px;overflow-x:auto;font-size:.78rem;font-family:ui-monospace,Menlo,Consolas,monospace;line-height:1.5}
.meta{color:#999;font-size:.8rem;margin-top:24px}
</style>
</head>
<body>
<h1><span>LinguaLayer</span> Test Report</h1>
<p class="subtitle">Generated {$this->nowString()}</p>

<div class="banner {$this->bannerClass($ready)}">
🎯 Production ready: {$ready}
</div>

<div class="cards">
  <div class="card">
    <div class="label">Tests passed</div>
    <div class="value good">{$passed}</div>
  </div>
  <div class="card">
    <div class="label">Tests failed</div>
    <div class="value {$this->failColor($failed)}">{$failed}</div>
  </div>
  <div class="card">
    <div class="label">Tests skipped</div>
    <div class="value warn">{$skipped}</div>
  </div>
  <div class="card">
    <div class="label">Pass rate</div>
    <div class="value good">{$rate}%</div>
  </div>
</div>

<div class="cards" style="grid-template-columns:repeat(2,1fr)">
  <div class="card">
    <div class="label">Duration</div>
    <div class="value">{$elapsed}s</div>
  </div>
  <div class="card">
    <div class="label">Total tests</div>
    <div class="value">{$total}</div>
  </div>
</div>

<h2 style="margin-bottom:12px;font-size:1.1rem">Raw output</h2>
<pre>{$this->escape($stripped)}</pre>

<p class="meta">LinguaLayer · MIT · Use <code>php artisan lingua:test-report</code> to regenerate.</p>
</body>
</html>
HTML;
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function nowString(): string
    {
        return now()->toIso8601String();
    }

    private function bannerClass(string $ready): string
    {
        return $ready === 'YES' ? 'ready' : 'notready';
    }

    private function failColor(int $failed): string
    {
        return $failed > 0 ? 'bad' : 'good';
    }
}

<?php

/**
 * Standalone report generator. Runs pest in the package repo and writes
 * tests/coverage-output/test-report.html. Used when there is no host
 * Laravel app handy (e.g. CI, local development of the package itself).
 *
 * Usage:  php bin/generate-test-report.php
 */
$root = dirname(__DIR__);
chdir($root);

$pestBin = $root.'/vendor/bin/pest';
if (! file_exists($pestBin)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}

echo "LinguaLayer · Test report\n";
echo "─────────────────────────\n";
echo "Running Pest…\n";

$start = microtime(true);
$cmd = escapeshellarg($pestBin).' --colors=never 2>&1';
$out = shell_exec($cmd) ?: '';
$elapsed = round(microtime(true) - $start, 1);

preg_match('/(\d+) passed/', $out, $mP);
preg_match('/(\d+) failed/', $out, $mF);
preg_match('/(\d+) skipped/', $out, $mS);
$passed = (int) ($mP[1] ?? 0);
$failed = (int) ($mF[1] ?? 0);
$skipped = (int) ($mS[1] ?? 0);
$total = $passed + $failed + $skipped;
$ready = $failed === 0 ? 'YES' : 'NO';
$rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n📊 LinguaLayer Test Report\n";
echo "══════════════════════════\n";
echo "  ✅ Tests passed:    {$passed}\n";
echo "  ❌ Tests failed:    {$failed}\n";
echo "  ⏭️  Tests skipped:   {$skipped}\n";
echo "  ⏱️  Duration:       {$elapsed}s\n";
echo "  🎯 Production ready: {$ready}\n";

$reportDir = $root.'/tests/coverage-output';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}
$reportFile = $reportDir.'/test-report.html';

$now = date('c');
$banner = $ready === 'YES' ? 'ready' : 'notready';
$failCol = $failed > 0 ? 'bad' : 'good';
$stripped = preg_replace('/\e\[[0-9;]*m/', '', $out);
$rawHtml = htmlspecialchars($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$html = <<<HTML
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
<p class="subtitle">Generated {$now}</p>

<div class="banner {$banner}">🎯 Production ready: {$ready}</div>

<div class="cards">
  <div class="card"><div class="label">Tests passed</div><div class="value good">{$passed}</div></div>
  <div class="card"><div class="label">Tests failed</div><div class="value {$failCol}">{$failed}</div></div>
  <div class="card"><div class="label">Tests skipped</div><div class="value warn">{$skipped}</div></div>
  <div class="card"><div class="label">Pass rate</div><div class="value good">{$rate}%</div></div>
</div>

<div class="cards" style="grid-template-columns:repeat(2,1fr)">
  <div class="card"><div class="label">Duration</div><div class="value">{$elapsed}s</div></div>
  <div class="card"><div class="label">Total tests</div><div class="value">{$total}</div></div>
</div>

<h2 style="margin-bottom:12px;font-size:1.1rem">Raw output</h2>
<pre>{$rawHtml}</pre>

<p class="meta">LinguaLayer · MIT · Use <code>php bin/generate-test-report.php</code> to regenerate.</p>
</body>
</html>
HTML;

file_put_contents($reportFile, $html);
echo "\nReport written to: {$reportFile}\n";
exit($failed > 0 ? 1 : 0);

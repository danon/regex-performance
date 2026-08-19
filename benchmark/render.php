<?php
/**
 * Renders benchmark/report.json into benchmark/report.html.
 *
 * Deliberately separate from the live CLI runner (benchmark/cli.php): the
 * TUI produces the data, this turns data already on disk into a page. Charts
 * are rendered by QuickChart (https://quickchart.io/) - a hosted Chart.js
 * renderer - and the returned PNGs are embedded as base64 data URIs, so the
 * resulting HTML file is self-contained and needs no network access to view.
 */

$jsonFile = __DIR__ . '/report.json';
if (!is_file($jsonFile)) {
    fwrite(STDERR, "No report.json found at {$jsonFile}. Run `php benchmark/cli.php` first.\n");
    exit(1);
}

$data = json_decode(file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);

$seriesColors = ['#2a78d6', '#eb6834', '#1baf7a'];
$methodNames = array_keys($data['methods']);

/**
 * POSTs a Chart.js config to QuickChart and returns the PNG bytes.
 *
 * @param array<string, mixed> $chartConfig
 */
function renderChart(array $chartConfig, int $width, int $height): string {
    $payload = json_encode([
        'chart'      => $chartConfig,
        'width'      => $width,
        'height'     => $height,
        'devicePixelRatio' => 2.0,
        'backgroundColor' => 'white',
        'format'     => 'png',
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init('https://quickchart.io/chart');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        throw new RuntimeException("QuickChart request failed (HTTP {$status}): {$error}");
    }

    return $body;
}

function toDataUri(string $pngBytes): string {
    return 'data:image/png;base64,' . base64_encode($pngBytes);
}

// ---------- Line chart: round-by-round convergence per method ----------
// QuickChart's free tier caps total chart data points (~500), so downsample
// each method's rounds to that budget by taking every Nth round rather than
// dropping the tail, which would hide whether/when it converged.
$maxRounds = max(array_map(static fn($m) => count($m['rounds']), $data['methods']));
$pointBudget = 200;
$stride = max(1, (int) ceil($maxRounds / $pointBudget));

$lineDatasets = [];
foreach ($methodNames as $i => $name) {
    $rounds = $data['methods'][$name]['rounds'];
    $sampledRounds = [];
    for ($r = 0; $r < count($rounds); $r += $stride) {
        $sampledRounds[] = $rounds[$r];
    }

    $lineDatasets[] = [
        'label'           => $name,
        'data'            => array_map(static fn($v) => round($v, 1), $sampledRounds),
        'borderColor'     => $seriesColors[$i % count($seriesColors)],
        'backgroundColor' => $seriesColors[$i % count($seriesColors)],
        'borderWidth'     => 2,
        'pointRadius'     => 0,
        'fill'            => false,
        'tension'         => 0,
    ];
}
$sampledLabels = [];
for ($r = 0; $r < $maxRounds; $r += $stride) {
    $sampledLabels[] = $r + 1;
}
$lineChart = [
    'type' => 'line',
    'data' => [
        'labels'   => $sampledLabels,
        'datasets' => $lineDatasets,
    ],
    'options' => [
        'plugins' => [
            'legend' => ['display' => true, 'position' => 'top'],
            'title'  => ['display' => true, 'text' => 'Round-by-round convergence (ns/op)'],
        ],
        'scales' => [
            'x' => ['title' => ['display' => true, 'text' => 'round #'], 'ticks' => ['maxTicksLimit' => 12]],
            'y' => ['title' => ['display' => true, 'text' => 'ns/op']],
        ],
    ],
];

// ---------- Histogram charts: distribution of converged round timings ----------
/**
 * Bins values into pre-defined bin edges (shared across methods so their
 * histograms line up on one chart).
 *
 * @param float[] $values
 * @param float[] $edges bin boundaries, length $binCount + 1
 * @return int[]
 */
function histogramWithEdges(array $values, array $edges): array {
    $binCount = count($edges) - 1;
    $counts = array_fill(0, $binCount, 0);
    foreach ($values as $v) {
        $bin = $binCount - 1;
        for ($i = 0; $i < $binCount; $i++) {
            if ($v < $edges[$i + 1] || $i === $binCount - 1) {
                $bin = $i;
                break;
            }
        }
        $counts[$bin]++;
    }
    return $counts;
}

$histogramTails = [];
foreach ($methodNames as $name) {
    $rounds = $data['methods'][$name]['rounds'];
    $histogramTails[$name] = array_slice($rounds, -min(100, count($rounds)));
}

$binCount = 20;
$allValues = array_merge(...array_values($histogramTails));
$min = min($allValues);
$max = max($allValues);
$binWidth = ($max - $min) / $binCount;

if ($binWidth <= 0.0) {
    $edges = [$min, $min + 1];
    $binCount = 1;
} else {
    $edges = [];
    for ($i = 0; $i <= $binCount; $i++) {
        $edges[] = $min + $i * $binWidth;
    }
}

$histogramLabels = [];
for ($i = 0; $i < $binCount; $i++) {
    $histogramLabels[] = number_format($edges[$i], 1);
}

$histogramDatasets = [];
foreach ($methodNames as $i => $name) {
    $histogramDatasets[] = [
        'label'           => $name,
        'data'            => histogramWithEdges($histogramTails[$name], $edges),
        'backgroundColor' => $seriesColors[$i % count($seriesColors)],
        'barPercentage'   => 0.9,
        'categoryPercentage' => 0.9,
    ];
}

$histogramChart = [
    'type' => 'bar',
    'data' => [
        'labels'   => $histogramLabels,
        'datasets' => $histogramDatasets,
    ],
    'options' => [
        'plugins' => [
            'legend' => ['display' => true, 'position' => 'top'],
            'title'  => ['display' => true, 'text' => 'Distribution of round timings'],
        ],
        'scales' => [
            'x' => ['title' => ['display' => true, 'text' => 'ns/op'], 'ticks' => ['maxTicksLimit' => 10]],
            'y' => ['title' => ['display' => true, 'text' => 'rounds'], 'beginAtZero' => true],
        ],
    ],
];

fwrite(STDERR, "Rendering line chart via QuickChart...\n");
$linePng = renderChart($lineChart, 900, 480);

fwrite(STDERR, "Rendering histogram chart via QuickChart...\n");
$histogramPng = renderChart($histogramChart, 900, 420);

// ---------- Summary table ----------
$baseline = $data['methods'][$methodNames[0]]['average'];
$roundsToConverge = $data['methods'][$methodNames[0]]['roundsRun'];
$tableRows = '';
foreach ($methodNames as $i => $name) {
    $m = $data['methods'][$name];
    $delta = $i === 0 ? '—' : sprintf('%+.0f%%', ($m['average'] / $baseline - 1) * 100);
    $cost = $i === 0
        ? number_format($m['average'], 0) . ' ns'
        : sprintf('%s ns + %s ns', number_format($baseline, 0), number_format($m['average'] - $baseline, 0));
    $tableRows .= sprintf(
        "<tr><td><span class=\"swatch\" style=\"background:%s\"></span>%s</td><td>%s</td><td class=\"num\">%s</td><td class=\"num\">%.1f</td><td class=\"num\">%s</td></tr>\n",
        htmlspecialchars($seriesColors[$i % count($seriesColors)]),
        htmlspecialchars($name),
        htmlspecialchars($cost),
        number_format($m['iterations']),
        $m['average'],
        htmlspecialchars($delta)
    );
}

$generatedAt = htmlspecialchars((new DateTimeImmutable($data['generated_at']))->format('Y-m-d H:i:s'));
$pattern = htmlspecialchars($data['pattern']);
$targetRoundSeconds = number_format($data['target_round_seconds'], 1);
$phpVersion = htmlspecialchars($data['php_version']);
$lineDataUri = toDataUri($linePng);
$histogramDataUri = toDataUri($histogramPng);

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>preg_match Call-Shape Benchmark</title>
<style>
:root {
  color-scheme: light dark;
  --surface-1:      #fcfcfb;
  --page:           #f9f9f7;
  --text-primary:   #0b0b0b;
  --text-secondary: #52514e;
  --text-muted:     #898781;
  --grid:           #e1e0d9;
  --border:         rgba(11,11,11,0.10);
}
@media (prefers-color-scheme: dark) {
  :root {
    --surface-1:      #1a1a19;
    --page:           #0d0d0d;
    --text-primary:   #ffffff;
    --text-secondary: #c3c2b7;
    --text-muted:     #898781;
    --grid:           #2c2c2a;
    --border:         rgba(255,255,255,0.10);
  }
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  background: var(--page);
  color: var(--text-primary);
  padding: 32px 20px 60px;
}
.wrap { max-width: 980px; margin: 0 auto; }
h1 { font-size: 20px; margin: 0 0 4px; }
.subtitle { color: var(--text-secondary); font-size: 13px; margin: 0 0 28px; }
.card {
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px 24px 24px;
  margin-bottom: 20px;
}
.card h2 { font-size: 14px; margin: 0 0 2px; }
.card .desc { color: var(--text-secondary); font-size: 12.5px; margin: 0 0 18px; }
.card img { width: 100%; height: auto; border-radius: 8px; background: #fff; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 6px; }
table.data-table th, table.data-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--grid); }
table.data-table th { color: var(--text-muted); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
table.data-table td.num, table.data-table th.num { text-align: right; }
table.data-table td.num { font-variant-numeric: tabular-nums; }
.swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; }
.meta { color: var(--text-muted); font-size: 11.5px; margin-top: 24px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>preg_match() call-shape benchmark</h1>
  <p class="subtitle">Pattern <code>{$pattern}</code> against a fixed email subject. Each method's iteration count was calibrated so a round takes roughly {$targetRoundSeconds}s, then rounds ran until its 20-round block median stopped moving (&lt;1% change for 4 consecutive blocks).</p>

  <div class="card">
    <h2>Converged cost per operation</h2>
    <p class="desc">Median of the tail rounds, once each method's noise floor was reached, {$roundsToConverge} rounds to converge across all methods. Costs beyond the baseline are shown as baseline + added cost.</p>
    <table class="data-table">
      <thead>
        <tr><th>Method</th><th>Cost</th><th class="num">Iterations</th><th class="num">ns/op</th><th class="num">vs. base</th></tr>
      </thead>
      <tbody>
{$tableRows}      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Round-by-round convergence</h2>
    <p class="desc">ns/op for every measured round. Watch each line settle onto its floor.</p>
    <img src="{$lineDataUri}" alt="Line chart of round-by-round ns/op per method">
  </div>

  <div class="card">
    <h2>Distribution of round timings</h2>
    <p class="desc">Histogram of ns/op across each method's last 100 (or fewer) rounds, once it had converged. Shows the spread and shape (e.g. GC/scheduler outliers) behind the summary averages.</p>
    <img src="{$histogramDataUri}" alt="Histogram of round timings per method">
  </div>

  <p class="meta">PHP {$phpVersion} &middot; generated {$generatedAt} &middot; ~{$targetRoundSeconds}s/round target &middot; charts rendered by quickchart.io</p>
</div>
</body>
</html>
HTML;

$outFile = __DIR__ . '/report1.html';
file_put_contents($outFile, $html);
fwrite(STDERR, "Wrote {$outFile}\n");

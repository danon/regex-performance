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

// ---------- Bar chart: converged average ns/op per method ----------
$barChart = [
    'type' => 'bar',
    'data' => [
        'labels'   => $methodNames,
        'datasets' => [[
            'label'           => 'ns/op',
            'data'            => array_map(static fn($m) => round($data['methods'][$m]['average'], 1), $methodNames),
            'backgroundColor' => $seriesColors,
        ]],
    ],
    'options' => [
        'plugins' => [
            'legend' => ['display' => false],
            'title'  => ['display' => true, 'text' => 'Converged cost per operation (ns/op)'],
            'datalabels' => [
                'anchor' => 'end',
                'align'  => 'top',
                'font'   => ['weight' => 'bold'],
            ],
        ],
        'scales' => [
            'y' => ['title' => ['display' => true, 'text' => 'ns/op'], 'beginAtZero' => true],
        ],
    ],
];

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
 * @param float[] $values
 * @return array{labels: string[], counts: int[]}
 */
function histogram(array $values, int $binCount = 20): array {
    $min = min($values);
    $max = max($values);
    $width = ($max - $min) / $binCount;
    if ($width <= 0.0) {
        return ['labels' => [number_format($min, 1)], 'counts' => [count($values)]];
    }

    $counts = array_fill(0, $binCount, 0);
    foreach ($values as $v) {
        $bin = (int) floor(($v - $min) / $width);
        $bin = min($bin, $binCount - 1);
        $counts[$bin]++;
    }

    $labels = [];
    for ($i = 0; $i < $binCount; $i++) {
        $labels[] = number_format($min + $i * $width, 1);
    }

    return ['labels' => $labels, 'counts' => $counts];
}

$histogramCharts = [];
foreach ($methodNames as $i => $name) {
    $rounds = $data['methods'][$name]['rounds'];
    $tail = array_slice($rounds, -min(100, count($rounds)));
    $hist = histogram($tail);

    $histogramCharts[$name] = [
        'type' => 'bar',
        'data' => [
            'labels'   => $hist['labels'],
            'datasets' => [[
                'label'           => $name,
                'data'            => $hist['counts'],
                'backgroundColor' => $seriesColors[$i % count($seriesColors)],
                'barPercentage'   => 1.0,
                'categoryPercentage' => 1.0,
            ]],
        ],
        'options' => [
            'plugins' => [
                'legend' => ['display' => false],
                'title'  => ['display' => true, 'text' => $name],
            ],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'ns/op'], 'ticks' => ['maxTicksLimit' => 10]],
                'y' => ['title' => ['display' => true, 'text' => 'rounds'], 'beginAtZero' => true],
            ],
        ],
    ];
}

fwrite(STDERR, "Rendering bar chart via QuickChart...\n");
$barPng = renderChart($barChart, 900, 420);

fwrite(STDERR, "Rendering line chart via QuickChart...\n");
$linePng = renderChart($lineChart, 900, 480);

$histogramDataUris = [];
foreach ($histogramCharts as $name => $config) {
    fwrite(STDERR, "Rendering histogram chart for {$name} via QuickChart...\n");
    $histogramDataUris[$name] = toDataUri(renderChart($config, 900, 280));
}

// ---------- Summary table ----------
$baseline = $data['methods'][$methodNames[0]]['average'];
$tableRows = '';
foreach ($methodNames as $i => $name) {
    $m = $data['methods'][$name];
    $delta = $i === 0 ? '—' : sprintf('%+.0f%%', ($m['average'] / $baseline - 1) * 100);
    $tableRows .= sprintf(
        "<tr><td><span class=\"swatch\" style=\"background:%s\"></span>%s</td><td class=\"num\">%s</td><td class=\"num\">%d</td><td class=\"num\">%.1f</td><td class=\"num\">%s</td></tr>\n",
        htmlspecialchars($seriesColors[$i % count($seriesColors)]),
        htmlspecialchars($name),
        number_format($m['iterations']),
        $m['roundsRun'],
        $m['average'],
        htmlspecialchars($delta)
    );
}

$generatedAt = htmlspecialchars((new DateTimeImmutable($data['generated_at']))->format('Y-m-d H:i:s'));
$pattern = htmlspecialchars($data['pattern']);
$targetRoundSeconds = number_format($data['target_round_seconds'], 1);
$phpVersion = htmlspecialchars($data['php_version']);
$barDataUri = toDataUri($barPng);
$lineDataUri = toDataUri($linePng);

$histogramImages = '';
foreach ($histogramDataUris as $name => $dataUri) {
    $histogramImages .= sprintf(
        "    <img src=\"%s\" alt=\"Histogram of round timings for %s\" style=\"margin-bottom:16px\">\n",
        $dataUri,
        htmlspecialchars($name)
    );
}

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
table.data-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
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
    <p class="desc">Median of the tail rounds, once each method's noise floor was reached.</p>
    <img src="{$barDataUri}" alt="Bar chart of converged ns/op per method">
  </div>

  <div class="card">
    <h2>Round-by-round convergence</h2>
    <p class="desc">ns/op for every measured round. Watch each line settle onto its floor.</p>
    <img src="{$lineDataUri}" alt="Line chart of round-by-round ns/op per method">
  </div>

  <div class="card">
    <h2>Distribution of round timings</h2>
    <p class="desc">Histogram of ns/op across each method's last 100 (or fewer) rounds, once it had converged. Shows the spread and shape (e.g. GC/scheduler outliers) behind the summary averages.</p>
{$histogramImages}  </div>

  <div class="card">
    <h2>Summary</h2>
    <table class="data-table">
      <thead>
        <tr><th>Method</th><th class="num">Iterations/round</th><th class="num">Rounds to converge</th><th class="num">ns/op</th><th class="num">vs. plain preg_match</th></tr>
      </thead>
      <tbody>
{$tableRows}      </tbody>
    </table>
  </div>

  <p class="meta">PHP {$phpVersion} &middot; generated {$generatedAt} &middot; ~{$targetRoundSeconds}s/round target &middot; charts rendered by quickchart.io</p>
</div>
</body>
</html>
HTML;

$outFile = __DIR__ . '/report1.html';
file_put_contents($outFile, $html);
fwrite(STDERR, "Wrote {$outFile}\n");

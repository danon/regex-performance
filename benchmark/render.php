<?php
/**
 * Renders a saved run into a self-contained HTML page.
 *
 *   php benchmark/render.php [report-name]
 *
 * Reads benchmark/reports/<report-name>.json and writes the .html beside it.
 *
 * Deliberately separate from the runner (benchmark/main.php): that produces the
 * data, this turns data already on disk into a page. It never runs a benchmark,
 * so re-rendering costs nothing and cannot disturb a measurement.
 */

use Benchmark\Harness\Html\Charts;
use Benchmark\Harness\Html\HtmlReport;
use Benchmark\Harness\Html\Palette;
use Benchmark\Harness\Html\QuickChart;
use Benchmark\Harness\Reports;

require __DIR__ . '/../vendor/autoload.php';

// Matches the default in benchmark/main.php, so `php benchmark/render.php`
// renders whatever `php benchmark/main.php` just wrote.
$name = $argv[1] ?? 'preg-match-call-shapes';

$reports = new Reports(__DIR__ . '/reports');
if (!is_file($reports->jsonPath($name))) {
    fwrite(STDERR, "No report named '{$name}' at {$reports->jsonPath($name)}. Run `php benchmark/main.php {$name}` first.\n");
    exit(1);
}

$report = $reports->load($name);

$palette = new Palette();
$charts = new Charts($palette);
$quickChart = new QuickChart(30);

fwrite(STDERR, "Rendering line chart via QuickChart...\n");
$convergenceUri = QuickChart::toDataUri($quickChart->renderPng($charts->convergence($report, 200), 900, 480));

fwrite(STDERR, "Rendering histogram chart via QuickChart...\n");
$distributionUri = QuickChart::toDataUri($quickChart->renderPng($charts->distribution($report, 100, 20), 900, 420));

$html = (new HtmlReport($palette))->render($report, $name, $convergenceUri, $distributionUri);

$path = $reports->saveHtml($html, $name);
fwrite(STDERR, "Wrote {$path}\n");

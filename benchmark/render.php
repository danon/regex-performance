<?php
/**
 * Renders benchmark/report.json into benchmark/report.html.
 *
 * Deliberately separate from the live CLI runner (benchmark/cli.php): the TUI
 * produces the data, this turns data already on disk into a page. It never runs
 * a benchmark, so re-rendering costs nothing and cannot disturb a measurement.
 */

use Benchmark\Harness\Html\Charts;
use Benchmark\Harness\Html\HtmlReport;
use Benchmark\Harness\Html\Palette;
use Benchmark\Harness\Html\QuickChart;
use Benchmark\Harness\Report;

require __DIR__ . '/../vendor/autoload.php';

$jsonFile = __DIR__ . '/report.json';
if (!is_file($jsonFile)) {
    fwrite(STDERR, "No report.json found at {$jsonFile}. Run `php benchmark/cli.php` first.\n");
    exit(1);
}

$report = Report::fromJson(file_get_contents($jsonFile));

$palette = new Palette();
$charts = new Charts($palette);
$quickChart = new QuickChart(30);

fwrite(STDERR, "Rendering line chart via QuickChart...\n");
$convergenceUri = QuickChart::toDataUri($quickChart->renderPng($charts->convergence($report, 200), 900, 480));

fwrite(STDERR, "Rendering histogram chart via QuickChart...\n");
$distributionUri = QuickChart::toDataUri($quickChart->renderPng($charts->distribution($report, 100, 20), 900, 420));

$html = (new HtmlReport($palette))->render($report, $convergenceUri, $distributionUri);

$outFile = __DIR__ . '/report.html';
file_put_contents($outFile, $html);
fwrite(STDERR, "Wrote {$outFile}\n");

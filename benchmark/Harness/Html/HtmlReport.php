<?php
namespace Benchmark\Harness\Html;

use Benchmark\Harness\Report;

/**
 * Renders a finished Report and its two chart images into one self-contained
 * HTML page.
 *
 * Takes the charts as data URIs rather than fetching them: what this class
 * knows about is layout, and keeping the network call outside it means the page
 * can be built from images that came from anywhere.
 */
final class HtmlReport
{
    public function __construct(private readonly Palette $palette) {
    }

    public function render(Report $report, string $name, string $convergenceUri, string $distributionUri): string {
        $baseline = $report->baseline();

        $rows = '';
        $index = 0;
        foreach ($report->methods as $method) {
            $color = $this->palette->colorAt($index);
            $row = $method === $baseline
                ? SummaryRow::baseline($method, $color)
                : SummaryRow::against($method, $baseline, $color);
            $rows .= $this->renderRow($row);
            $index++;
        }

        $title = htmlspecialchars($name);
        $phpVersion = htmlspecialchars($report->phpVersion);
        $generatedAt = htmlspecialchars($report->generatedAt->format('Y-m-d H:i:s'));
        $targetRoundSeconds = number_format($report->targetRoundSeconds, 1);
        $roundsRun = number_format($baseline->roundsRun);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <title>{$title} benchmark</title>
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
          <h1>{$title}</h1>
          <p class="subtitle">Each method's iteration count was calibrated so a round takes roughly {$targetRoundSeconds}s, then rounds ran until every method's block median had stopped moving.</p>

          <div class="card">
            <h2>Converged cost per operation</h2>
            <p class="desc">Median of the tail rounds, once each method's noise floor was reached, {$roundsRun} rounds to converge across all methods. Every method is shown as the baseline plus or minus what it changes.</p>
            <table class="data-table">
              <thead>
                <tr><th>Method</th><th>Cost</th><th class="num">vs. base</th></tr>
              </thead>
              <tbody>
        {$rows}      </tbody>
            </table>
          </div>

          <div class="card">
            <h2>Round-by-round convergence</h2>
            <p class="desc">ns/op for every measured round. Watch each line settle onto its floor.</p>
            <img src="{$convergenceUri}" alt="Line chart of round-by-round ns/op per method">
          </div>

          <div class="card">
            <h2>Distribution of round timings</h2>
            <p class="desc">Histogram of ns/op across each method's last 100 (or fewer) rounds, once it had converged. Shows the spread and shape (e.g. GC/scheduler outliers) behind the summary averages.</p>
            <img src="{$distributionUri}" alt="Histogram of round timings per method">
          </div>

          <p class="meta">PHP {$phpVersion} &middot; generated {$generatedAt} &middot; ~{$targetRoundSeconds}s/round target &middot; charts rendered by quickchart.io</p>
        </div>
        </body>
        </html>
        HTML;
    }

    private function renderRow(SummaryRow $row): string {
        return sprintf(
            "<tr><td><span class=\"swatch\" style=\"background:%s\"></span>%s</td><td>%s</td><td class=\"num\">%s</td></tr>\n",
            htmlspecialchars($row->color),
            htmlspecialchars($row->name),
            htmlspecialchars($row->cost),
            htmlspecialchars($row->delta),
        );
    }
}

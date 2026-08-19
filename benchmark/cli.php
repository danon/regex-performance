<?php
/**
 * Live TUI benchmark runner.
 *
 * Runs the call-shape benchmarks interleaved, round-robin - one round of each
 * subject in turn, not all of one subject before starting the next - so they
 * share the same stretch of wall-clock time and any CPU throttling or
 * background noise hits all of them alike. Redraws an in-place terminal table
 * with each subject's round count, latest round time, block-median streak, and
 * (once converged) its converged figure. Writes benchmark/report.json when
 * done. Rendering the HTML report is a separate step - see
 * benchmark/render.php.
 *
 * What is being measured lives in benchmark/Measured; everything that does the
 * measuring lives in benchmark/Harness. This file only wires the two together.
 */

use Benchmark\Harness\Calibrator;
use Benchmark\Harness\Convergence;
use Benchmark\Harness\ConvergenceSettings;
use Benchmark\Harness\Report;
use Benchmark\Harness\Tui\LiveTable;
use Benchmark\Harness\Tui\Row;
use Benchmark\Measured\CallShapes;

require __DIR__ . '/../vendor/autoload.php';

LiveTable::enableAnsi();

$pattern = '/^[\w.+-]+@[\w-]+\.[\w.]{2,}$/';
$subject = 'daniel.wilkowski@example.co.uk';
// Override with BENCH_TARGET_SECONDS=0.05 php benchmark/cli.php for a quick
// smoke test. 0.5s keeps each round comfortably above scheduler/timer noise
// while still redrawing the TUI a couple of times a second per subject.
$targetRoundSeconds = (float)(getenv('BENCH_TARGET_SECONDS') ?: 0.5);

$subjects = CallShapes::all($pattern, $subject);

$table = new LiveTable($pattern, $subject, $targetRoundSeconds, 0.05);

/** @var Row[] $rows */
$rows = [];
foreach ($subjects as $measured) {
    $rows[$measured->name] = Row::pending($measured->name);
}
$table->draw($rows);

// Calibrate each subject's iteration count separately so every round lands near
// $targetRoundSeconds regardless of how cheap or expensive that subject's call
// shape is - a 50ns function and a slower one shouldn't share one hardcoded
// iteration count.
$calibrator = new Calibrator($targetRoundSeconds, 1000, 100_000_000);

$iterationsByName = [];
foreach ($subjects as $measured) {
    $onAttempt = static function (int $iterations, float $elapsedSeconds) use (&$rows, $table, $measured): void {
        $rows[$measured->name] = Row::calibrating($measured->name, $iterations, $elapsedSeconds);
        $table->draw($rows);
    };

    $iterationsByName[$measured->name] = $calibrator->calibrate($measured->body, $onAttempt);
}

$settings = new ConvergenceSettings(20, 0.01, 4, 3000, 10);

/** @var Convergence[] $convergence */
$convergence = [];
foreach ($subjects as $measured) {
    $convergence[$measured->name] = new Convergence($settings);
    $rows[$measured->name] = Row::measuring(
        $measured->name,
        $iterationsByName[$measured->name],
        $convergence[$measured->name]->progress(),
    );
}
$table->draw($rows);

// Round-robin: one round of each subject per pass, not all of one subject
// before the next, so they share the same stretch of wall-clock time. Every
// subject keeps taking rounds every pass - even ones already past their own
// streak - because Convergence re-checks the streak fresh each round; only once
// ALL of them are converged on the same pass does the run stop.
$allConverged = false;
while (!$allConverged) {
    $allConverged = true;

    foreach ($subjects as $measured) {
        $running = $convergence[$measured->name];
        $running->step($measured->body, $iterationsByName[$measured->name]);

        $rows[$measured->name] = Row::measuring(
            $measured->name,
            $iterationsByName[$measured->name],
            $running->progress(),
        );

        if (!$running->isConverged()) {
            $allConverged = false;
        }

        // Redraw after each subject's round, not just once per full pass - each
        // round already takes ~$targetRoundSeconds on its own, so waiting for
        // every subject before redrawing would multiply the gap between visible
        // updates.
        $table->drawIfDue($rows);
    }
}

$table->draw($rows);

$results = [];
foreach ($subjects as $measured) {
    $results[$measured->name] = $convergence[$measured->name]
        ->result($measured->name, $iterationsByName[$measured->name]);
}

$report = new Report(
    $pattern,
    $subject,
    $targetRoundSeconds,
    PHP_VERSION,
    new DateTimeImmutable(),
    $results,
);

$outFile = __DIR__ . '/report.json';
file_put_contents($outFile, $report->toJson());

echo "\nWrote {$outFile}\n";
echo "Run `php benchmark/render.php` to build the HTML report.\n";

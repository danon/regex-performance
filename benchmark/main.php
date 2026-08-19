<?php
/**
 * The benchmark itself: what gets measured, and what the run is called.
 *
 * Everything under benchmark/Harness is generic - it times named loop bodies
 * and knows nothing about regular expressions. This file is the other half: the
 * only place that says what those bodies do. Adding or changing a call shape
 * means editing this file and nothing else.
 *
 *   php benchmark/main.php [--quick|--full] [report-name]
 *
 * --full (the default) measures until the timings settle; --quick trades that
 * accuracy for a run that finishes in seconds, which is what you want while
 * changing the call shapes above or the harness itself.
 *
 * The report is written to benchmark/reports/<report-name>.json, so runs can be
 * kept side by side - before and after a change, one PHP version and the next -
 * instead of overwriting each other. Render one with:
 *
 *   php benchmark/render.php [report-name]
 */

use Benchmark\Harness\Benchmark;
use Benchmark\Harness\Calibrator;
use Benchmark\Harness\ConvergenceSettings;
use Benchmark\Harness\Reports;
use Benchmark\Harness\Ui\CliInterface;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// The call shapes under benchmark.
//
// Global functions, not methods, because that is the shape preg_test() has: a
// static method call and a plain function call do not cost the same, and the
// difference is a visible share of what is being measured here.
// ---------------------------------------------------------------------------

/** One preg_match() behind one userland call - the same shape as preg_test(). */
function matchOnce(string $pattern, string $subject): bool {
    return preg_match($pattern, $subject) === 1;
}

/**
 * The same three times, so matchThrice() minus matchOnce() is two matches and
 * no call overhead at all - which prices a preg_match() from inside userland.
 *
 * The results are collected before they are combined: `&&` would short circuit
 * on a subject that does not match, and only two calls, or one, would actually
 * run.
 */
function matchThrice(string $pattern, string $subject): bool {
    $first = preg_match($pattern, $subject) === 1;
    $second = preg_match($pattern, $subject) === 1;
    $third = preg_match($pattern, $subject) === 1;

    return $first && $second && $third;
}

// ---------------------------------------------------------------------------
// The run.
// ---------------------------------------------------------------------------

$options = [];
$positional = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $options[] = $arg;
    } else {
        $positional[] = $arg;
    }
}

$unknown = array_diff($options, ['--quick', '--full']);
if ($unknown !== []) {
    fwrite(STDERR, "Unknown option: " . implode(' ', $unknown) . "\n");
    fwrite(STDERR, "Usage: php benchmark/main.php [--quick|--full] [report-name]\n");
    exit(1);
}

$quick = in_array('--quick', $options, true);
$name = $positional[0] ?? 'preg-match-call-shapes';

$pattern = '/^[\w.+-]+@[\w-]+\.[\w.]{2,}$/';
$subject = 'daniel.wilkowski@example.co.uk';

// A full round of 0.5s stays comfortably above scheduler/timer noise while
// still redrawing the table a couple of times a second per method. A quick
// round is short enough that the noise it lets through is real - it is there to
// prove the run works end to end, not to be quoted.
$calibrator = $quick
    ? new Calibrator(0.05, 1_000, 100_000_000)
    : new Calibrator(0.5, 1_000, 100_000_000);

// Quick mode also stops asking for as much agreement: shorter blocks, a looser
// tolerance, a shorter streak, and a cap low enough to bound the whole run.
$convergence = $quick
    ? new ConvergenceSettings(5, 0.05, 2, 60, 3)
    : new ConvergenceSettings(20, 0.01, 4, 3_000, 10);

$benchmark = new Benchmark(new CliInterface($name), $calibrator, $convergence);

// Baseline first - every other method is reported against it. Each body assigns
// its result to a variable that is never read, identically in all four:
// dropping the assignment in some and not others would compare loops that do
// different amounts of work.
$report = $benchmark->measure([
    'plain (inline preg_match)'        => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = preg_match($pattern, $subject) === 1;
        }
    },
    'preg_test (library wrapper)'      => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = preg_test($pattern, $subject);
        }
    },
    'matchOnce (1 preg_match call)'    => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = matchOnce($pattern, $subject);
        }
    },
    'matchThrice (3 preg_match calls)' => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = matchThrice($pattern, $subject);
        }
    },
]);

$path = (new Reports(__DIR__ . '/reports'))->save($report, $name);

echo "\nWrote {$path}\n";
echo "Run `php benchmark/render.php {$name}` to build the HTML report.\n";

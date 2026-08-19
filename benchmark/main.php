<?php
/**
 * The benchmark itself: what gets measured, and what the run is called.
 *
 * Everything under benchmark/Harness is generic - it times named loop bodies
 * and knows nothing about regular expressions. This file is the other half: the
 * only place that says what those bodies do. Adding or changing a call shape
 * means editing this file and nothing else.
 *
 *   php benchmark/main.php [report-name]
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

$name = $argv[1] ?? 'preg-match-call-shapes';

$pattern = '/^[\w.+-]+@[\w-]+\.[\w.]{2,}$/';
$subject = 'daniel.wilkowski@example.co.uk';

// Override with BENCH_TARGET_SECONDS=0.05 php benchmark/main.php for a quick
// smoke test. 0.5s keeps each round comfortably above scheduler/timer noise
// while still redrawing the table a couple of times a second per method.
$targetRoundSeconds = (float)(getenv('BENCH_TARGET_SECONDS') ?: 0.5);

$benchmark = new Benchmark(
    new CliInterface($name),
    new Calibrator($targetRoundSeconds, 1_000, 100_000_000),
    new ConvergenceSettings(20, 0.01, 4, 3_000, 10),
);

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

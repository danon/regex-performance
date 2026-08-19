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
// Global functions, not methods: a static method call and a plain function call
// do not cost the same, and the difference is a visible share of what is being
// measured here.
// ---------------------------------------------------------------------------

/**
 * One preg_match() behind one userland call, so matchOnce() minus the inline
 * baseline is the price of a userland call and nothing else.
 */
function matchOnce(string $pattern, string $subject): bool {
    return preg_match($pattern, $subject) === 1;
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

// The same pattern with the second character class left unterminated, which
// PCRE refuses to compile: "missing terminating ] for character class". A
// missing delimiter would not do - PHP rejects that before PCRE is reached, so
// it would not price a real compilation failure.
$brokenPattern = '/^[\w.+-]+@[\w-]+\.[\w.{2,}$/';

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
// its result to a variable that is never read, identically in all of them:
// dropping the assignment in some and not others would compare loops that do
// different amounts of work.
//
// The last three price the idiom for finding out whether preg_match() warned:
// clear the last error, call, then read the error back. It has to be done that
// way round because a custom error handler is not an option - one that returns
// true stops error_get_last() being populated at all, which is the very thing
// being read.
$report = $benchmark->measure([
    'plain (inline preg_match)'        => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = preg_match($pattern, $subject) === 1;
        }
    },
    // Nothing to scan, so this is the floor: what a preg_match() costs before
    // any of the subject has been looked at. The gap to the baseline is what
    // matching those 30 characters actually costs.
    'plain (empty subject)'            => static function (int $n) use ($pattern): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = preg_match($pattern, '') === 1;
        }
    },
    'matchOnce (1 preg_match call)'    => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            $matched = matchOnce($pattern, $subject);
        }
    },
    // Half the idiom: clearing beforehand, without reading anything back. The
    // gap to the baseline is what error_clear_last() costs on its own.
    'clear error + match'              => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            error_clear_last();
            $matched = preg_match($pattern, $subject) === 1;
        }
    },
    // The whole idiom on the happy path, where there is no error to find.
    'clear + match + read (no error)'  => static function (int $n) use ($pattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            error_clear_last();
            $matched = @preg_match($pattern, $subject) === 1;
            $error = error_get_last();
        }
    },
    // The same, but the pattern does not compile, so there is a warning to
    // clear, raise and read on every iteration.
    //
    // Two things make this not a like-for-like comparison against the row
    // above, and both are inherent rather than accidental. The warning has to
    // be suppressed - without `@` PHP prints it on every one of the tens of
    // thousands of iterations in a round, which would swamp both the terminal
    // and the timing - so this row carries the cost of suppression too. And a
    // pattern that fails to compile is not cached, so every call recompiles it
    // and fails again: what is priced here is a failing compile, not just the
    // reading of an error.
    'clear + match + read (error)'     => static function (int $n) use ($brokenPattern, $subject): void {
        for ($i = 0; $i < $n; $i++) {
            error_clear_last();
            $matched = @preg_match($brokenPattern, $subject) === 1;
            $error = error_get_last();
        }
    },
    // The other way of finding out whether preg_match() warned: install a
    // handler around the call, then put back whatever was there before.
    //
    // It costs two calls either side of the match, the same as the idiom above,
    // but it answers a question the other one cannot. error_get_last() reports
    // the last error from anywhere, so it cannot tell a warning this call
    // raised from one left behind earlier - clearing first is what makes it
    // usable at all. A handler is installed around this call only, so anything
    // it catches came from this call.
    //
    // The closure is built once rather than per iteration, so what is priced
    // here is set_error_handler() and restore_error_handler() and not the
    // allocation of a handler. An implementation that builds a fresh closure
    // per call pays more than this row shows.
    'set + match + restore (no error)' => static function (int $n) use ($pattern, $subject): void {
        $error = null;
        $handler = static function (int $code, string $message) use (&$error): bool {
            $error = $message;
            return true;
        };

        for ($i = 0; $i < $n; $i++) {
            $error = null;
            set_error_handler($handler);
            $matched = preg_match($pattern, $subject) === 1;
            restore_error_handler();
        }
    },
    // The same, against the pattern that will not compile, so the handler is
    // actually entered on every iteration.
    //
    // Unlike its counterpart above this needs no `@`: a handler that returns
    // true has already told PHP the warning is dealt with, so nothing is
    // printed and nothing has to be suppressed. That makes this the one error
    // row that is directly comparable to its own no-error row - the difference
    // between the two is the error and nothing else. The failing compile is
    // still uncached and repeated on every call, the same as the row above.
    'set + match + restore (error)'    => static function (int $n) use ($brokenPattern, $subject): void {
        $error = null;
        $handler = static function (int $code, string $message) use (&$error): bool {
            $error = $message;
            return true;
        };

        for ($i = 0; $i < $n; $i++) {
            $error = null;
            set_error_handler($handler);
            $matched = preg_match($brokenPattern, $subject) === 1;
            restore_error_handler();
        }
    },
]);

$path = (new Reports(__DIR__ . '/reports'))->save($report, $name);

echo "\nWrote {$path}\n";
echo "Run `php benchmark/render.php {$name}` to build the HTML report.\n";

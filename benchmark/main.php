<?php
/**
 * The benchmark itself: what gets measured, and what the run is called.
 *
 * Everything under benchmark/Harness is generic - it times named loop bodies
 * and knows nothing about regular expressions. This file is the other half: the
 * only place that says what those bodies do. Adding or changing a call shape
 * means editing this file and nothing else.
 *
 *   php benchmark/main.php [--quick|--1min|--5min|--full] [report-name]
 *
 * The mode is how long you are willing to wait, and each one is a ceiling on
 * the whole run rather than a promise to use it all:
 *
 *   --quick   up to 15 seconds  - proves the run works end to end; not to be
 *                                 quoted, the noise it lets through is real
 *   --1min    up to 1 minute    - enough to see whether a change moved anything
 *   --5min    up to 5 minutes   - close enough to compare numbers with
 *   --full    up to 15 minutes  - the default; measures until the timings
 *                                 settle, usually landing between 8 and 15
 *
 * A run that settles early stops early, so the shorter modes typically come in
 * under their name. The longer ones ask for more agreement before they call a
 * method settled, so they typically do not.
 *
 * The report is written to benchmark/reports/<report-name>.json, so runs can be
 * kept side by side - before and after a change, one PHP version and the next -
 * instead of overwriting each other. Render one with:
 *
 *   php benchmark/render.php [report-name]
 */

use Benchmark\Harness\Benchmark;
use Benchmark\Harness\Calibrator;
use Benchmark\Harness\Reports;
use Benchmark\Harness\RunPreset;
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

/**
 * Checking in batches instead of per call: install the handler once, run
 * $batchSize matches under it, ask preg_last_error() whether PCRE itself gave
 * up - on a backtrack or recursion limit, which a warning would not tell you
 * about - and put the old handler back.
 *
 * $n is the number of times the harness calls this closure, i.e. the number
 * of batches, not the number of matches - so the reported ns/op is the cost
 * of one whole batch (its $batchSize matches included), not divided back down
 * to a per-match figure. That is deliberate: it is what lets a bigger
 * $batchSize show up as a proportionally bigger cost instead of quietly
 * normalising itself away.
 */
function batchedMatchWithHandler(int $batchSize, string $pattern, string $subject): Closure {
    return static function (int $n) use ($batchSize, $pattern, $subject): void {
        $error = null;
        $handler = static function (int $code, string $message) use (&$error): bool {
            $error = $message;
            return true;
        };
        for ($batch = 0; $batch < $n; $batch++) {
            $error = null;
            set_error_handler($handler);
            for ($i = 0; $i < $batchSize; $i++) {
                $matched = preg_match($pattern, $subject) === 1;
            }
            $failure = preg_last_error();
            restore_error_handler();
        }
    };
}

/**
 * The same batching with nothing checked at all - no handler, no
 * preg_last_error() - so the pair differs by exactly the checking. It also
 * doubles as a control on the batching itself: the nested loop should cost
 * $batchSize times what the flat baseline costs, and any gap beyond that
 * multiple would mean the shape of the loop is being measured rather than the
 * work inside it.
 */
function batchedMatchUnchecked(int $batchSize, string $pattern, string $subject): Closure {
    return static function (int $n) use ($batchSize, $pattern, $subject): void {
        for ($batch = 0; $batch < $n; $batch++) {
            for ($i = 0; $i < $batchSize; $i++) {
                $matched = preg_match($pattern, $subject) === 1;
            }
        }
    };
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

$unknown = array_diff($options, ['--quick', '--1min', '--5min', '--full']);
if ($unknown !== []) {
    fwrite(STDERR, "Unknown option: " . implode(' ', $unknown) . "\n");
    fwrite(STDERR, "Usage: php benchmark/main.php [--quick|--1min|--5min|--full] [report-name]\n");
    exit(1);
}

// One flag could be allowed to quietly beat another, but with four of them
// silently picking one is worse than saying nothing was picked.
$modes = array_values(array_unique(array_intersect($options, ['--quick', '--1min', '--5min', '--full'])));
if (count($modes) > 1) {
    \fWrite(STDERR, "Pick one mode, not " . implode(' ', $modes) . "\n");
    exit(1);
}

$mode = $modes[0] ?? '--full';
$name = $positional[0] ?? 'preg-match-call-shapes';

$pattern = '/^[\w.+-]+@[\w-]+\.[\w.]{2,}$/';
$subject = 'daniel.wilkowski@example.co.uk';

// The same pattern with the second character class left unterminated, which
// PCRE refuses to compile: "missing terminating ] for character class". A
// missing delimiter would not do - PHP rejects that before PCRE is reached, so
// it would not price a real compilation failure.
$brokenPattern = '/^[\w.+-]+@[\w-]+\.[\w.{2,}$/';

// Each mode is a time budget with thresholds to match. A longer round averages
// out more scheduler and timer noise; a longer budget buys the rounds needed to
// hold a tighter tolerance for a longer streak. Every knob moves in step, so
// each preset is stricter than the one above it on all of them, and the round
// cap each one needs is worked out from its budget rather than guessed - see
// RunPreset.
//
// The budget is a ceiling. A run that settles early stops early, which is why
// the shorter modes usually come in well under their name and the longer ones,
// asking for more agreement, usually do not.
$preset = match ($mode) {
    '--quick' => new RunPreset(0.05, 15, 5, 0.05, 2, 3),
    '--1min'  => new RunPreset(0.1, 60, 10, 0.03, 2, 5),
    '--5min'  => new RunPreset(0.25, 300, 15, 0.015, 3, 8),
    '--full'  => new RunPreset(0.5, 900, 20, 0.01, 4, 10),
};

// Baseline first in each group - every other method in that group is reported
// against it. Each body assigns its result to a variable that is never read,
// identically in all of them: dropping the assignment in some and not others
// would compare loops that do different amounts of work.
//
// Groups exist because a batched call is not a fair comparison against a
// single one - a 2000x row costs roughly 2000 times what a single call does,
// and reporting that against the single-call baseline would swamp the actual
// question (what does checking cost at that batch size) with the unrelated
// fact that a batch does more work than one call. Each group is judged only
// against its own first member.
$subjectGroups = [
    '1 compilation + 1 execution'      => [
        'preg_match() inline'                                         => static function (int $n) use ($pattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = preg_match($pattern, $subject) === 1;
            }
        },
        // The same shape, against the pattern that will not compile. No error
        // is read back here - nothing clears or checks it - so the gap to the
        // baseline is purely the cost of a failing, uncached compile on every
        // call. The warning still has to be suppressed with `@`, or it would
        // print on every iteration of every round.
        'preg_match() inline (error)'                                 => static function (int $n) use ($brokenPattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = @preg_match($brokenPattern, $subject) === 1;
            }
        },
        // Nothing to scan, so this is the floor: what a preg_match() costs
        // before any of the subject has been looked at. The gap to the
        // baseline is what matching those 30 characters actually costs.
        'preg_match(empty)'                                           => static function (int $n) use ($pattern): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = preg_match($pattern, '') === 1;
            }
        },
        // Emptier still: nothing to compile and nothing to scan. Whatever
        // this costs is what preg_match() charges for being called at all,
        // and no pattern or subject can bring it below.
        'preg_match(//, empty)'                                       => static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = preg_match('//', '') === 1;
            }
        },
        'preg_match(subject)'                                         => static function (int $n) use ($pattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = matchOnce($pattern, $subject);
            }
        },
        // Half the idiom: clearing beforehand, without reading anything back.
        // The gap to the baseline is what error_clear_last() costs on its own.
        'clear() + preg_match()'                                      => static function (int $n) use ($pattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                error_clear_last();
                $matched = preg_match($pattern, $subject) === 1;
            }
        },
        // The whole idiom on the happy path, where there is no error to find.
        'clear() + preg_match() + read() (no error)'                  => static function (int $n) use ($pattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                error_clear_last();
                $matched = @preg_match($pattern, $subject) === 1;
                $error = error_get_last();
            }
        },
        // The same, but the pattern does not compile, so there is a warning
        // to clear, raise and read on every iteration.
        //
        // Two things make this not a like-for-like comparison against the row
        // above, and both are inherent rather than accidental. The warning
        // has to be suppressed - without `@` PHP prints it on every one of
        // the tens of thousands of iterations in a round, which would swamp
        // both the terminal and the timing - so this row carries the cost of
        // suppression too. And a pattern that fails to compile is not
        // cached, so every call recompiles it and fails again: what is
        // priced here is a failing compile, not just the reading of an error.
        'clear() + preg_match() + read() (error)'                     => static function (int $n) use ($brokenPattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                error_clear_last();
                $matched = @preg_match($brokenPattern, $subject) === 1;
                $error = error_get_last();
            }
        },
        // The other way of finding out whether preg_match() warned: install
        // a handler around the call, then put back whatever was there before.
        //
        // It costs two calls either side of the match, the same as the idiom
        // above, but it answers a question the other one cannot.
        // error_get_last() reports the last error from anywhere, so it
        // cannot tell a warning this call raised from one left behind
        // earlier - clearing first is what makes it usable at all. A handler
        // is installed around this call only, so anything it catches came
        // from this call.
        //
        // The closure is built once rather than per iteration, so what is
        // priced here is set_error_handler() and restore_error_handler() and
        // not the allocation of a handler. An implementation that builds a
        // fresh closure per call pays more than this row shows.
        'set_handler() + preg_match() + restore_handler() (no error)' => static function (int $n) use ($pattern, $subject): void {
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
        // The same, against the pattern that will not compile, so the
        // handler is actually entered on every iteration.
        //
        // Unlike its counterpart above this needs no `@`: a handler that
        // returns true has already told PHP the warning is dealt with, so
        // nothing is printed and nothing has to be suppressed. That makes
        // this the one error row that is directly comparable to its own
        // no-error row - the difference between the two is the error and
        // nothing else. The failing compile is still uncached and repeated
        // on every call, the same as the row above.
        'set_handler() + preg_match() + restore_handler() (error)'    => static function (int $n) use ($brokenPattern, $subject): void {
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
    ],
    // The inline call batched 50x, as this group's own baseline, and the
    // handler-checking idiom batched the same way beside it - so the gap
    // between the two is exactly what checking costs once every 50 matches,
    // not muddied by the fact that a batch of 50 costs more than one call.
    '1 compilation + 50x executions'   => [
        '50x preg_match() inline'                => batchedMatchUnchecked(50, $pattern, $subject),
        '50x preg_match() + handler + lastError' => batchedMatchWithHandler(50, $pattern, $subject),
    ],
    // The same pair at 2000x, to see whether a bigger batch amortises the
    // checking cost further or the 50x group already sits at the floor.
    '1 compilation + 2000x executions' => [
        '2000x preg_match() inline'                => batchedMatchUnchecked(2000, $pattern, $subject),
        '2000x preg_match() + handler + lastError' => batchedMatchWithHandler(2000, $pattern, $subject),
    ],
];

$subjectCount = array_sum(array_map('count', $subjectGroups));

// The cap comes from the preset and the number of methods together: they share
// the budget round-robin, so what each one may take depends on how many of them
// there are.
$benchmark = new Benchmark(
    new CliInterface($name),
    new Calibrator($preset->roundSeconds, 1_000, 100_000_000),
    $preset->convergenceFor($subjectCount),
);

$report = $benchmark->measure($subjectGroups);

$path = (new Reports(__DIR__ . '/reports'))->save($report, $name);

echo "\nWrote {$path}\n";
echo "Run `php benchmark/render.php {$name}` to build the HTML report.\n";

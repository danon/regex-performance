<?php
/**
 * Shared benchmark primitives used by both the live CLI runner
 * (benchmark/cli.php) and the HTML renderer (benchmark/render.php).
 */

require __DIR__ . '/../src/preg_test.php';

function matchOnce(string $pattern, string $subject): bool {
    return preg_match($pattern, $subject) === 1;
}

function matchThrice(string $pattern, string $subject): bool {
    $first = preg_match($pattern, $subject) === 1;
    $second = preg_match($pattern, $subject) === 1;
    $third = preg_match($pattern, $subject) === 1;

    return $first && $second && $third;
}

/**
 * @return array<string, callable(int):void>
 */
function benchmarkMethods(string $pattern, string $subject): array {
    return [
        'plain (inline preg_match)'        => static function (int $n) use ($pattern, $subject): void {
            for ($i = 0; $i < $n; $i++) {
                $matched = preg_match($pattern, $subject) === 1;
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
    ];
}

/**
 * @param callable(int):void $body
 */
function timeRound(callable $body, int $iterations): float {
    $start = hrtime(true);
    $body($iterations);
    $elapsed = hrtime(true) - $start;

    return $elapsed / 1e9;
}

/**
 * Finds an iteration count for which one round of $body takes roughly
 * $targetSeconds of wall-clock time. Hardcoding a fixed iteration count
 * means a 50ns function and a 10ms function get the same $n - one finishes
 * a round in microseconds (dominated by scheduler/timer noise, not the code
 * under test) and the other takes minutes. Calibrating against a duration
 * target instead keeps every method's round long enough to average out that
 * noise, without the caller having to know each method's per-op cost ahead
 * of time.
 *
 * Doubles $n from a small seed until a round takes at least $targetSeconds,
 * then scales linearly onto the target from that last measurement, since
 * round duration is ~linear in iteration count once JIT/opcode-cache
 * effects have settled during doubling.
 *
 * $onAttempt, if not null, is called after every doubling round with the
 * iteration count and elapsed seconds just measured, so a caller can show
 * progress instead of appearing to hang - each attempt round can itself
 * take up to $targetSeconds with nothing else happening in between.
 *
 * @param callable(int):void $body
 * @param null|callable(int $iterations, float $elapsedSeconds):void $onAttempt
 */
function calibrateIterations(
    callable  $body,
    float     $targetSeconds,
    int       $seedIterations,
    int       $maxIterations,
    ?callable $onAttempt
): int {
    $n = $seedIterations;
    $elapsed = timeRound($body, $n);
    if ($onAttempt !== null) {
        $onAttempt($n, $elapsed);
    }

    while ($elapsed < $targetSeconds && $n < $maxIterations) {
        $n = min($n * 2, $maxIterations);
        $elapsed = timeRound($body, $n);
        if ($onAttempt !== null) {
            $onAttempt($n, $elapsed);
        }
    }

    if ($elapsed <= 0.0) {
        return $n;
    }

    $scaled = (int)round($n * ($targetSeconds / $elapsed));

    return max($seedIterations, min($scaled, $maxIterations));
}

/**
 * @param float[] $values
 */
function median(array $values): float {
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);

    return $count % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
}

/**
 * Builds the initial state for driving one method's rounds one step at a
 * time via stepConvergence(), so a caller can interleave rounds across
 * several methods instead of running each to completion before starting the
 * next.
 *
 * @return array{
 *   warmupRemaining: int, warmupTotal: int, roundsNs: float[], prevBlockMedian: ?float,
 *   streak: int, round: int, lastNs: ?float, blockMedian: ?float,
 *   converged: bool, average: ?float,
 *   window: int, tolerance: float, stableStreak: int, maxRounds: int,
 * }
 */
function newConvergenceState(
    int   $window,
    float $tolerance,
    int   $stableStreak,
    int   $maxRounds,
    int   $warmup
): array {
    return [
        'warmupRemaining' => $warmup,
        'warmupTotal'     => $warmup,
        'roundsNs'        => [],
        'prevBlockMedian' => null,
        'streak'          => 0,
        'round'           => 0,
        'lastNs'          => null,
        'blockMedian'     => null,
        'converged'       => false,
        'average'         => null,
        'window'          => $window,
        'tolerance'       => $tolerance,
        'stableStreak'    => $stableStreak,
        'maxRounds'       => $maxRounds,
    ];
}

/**
 * Runs a single warmup or measured round for one method and updates its
 * state in place. Rounds are timed in non-overlapping blocks of
 * $state['window'] rounds; the method is marked converged once
 * $state['stableStreak'] consecutive block medians each land within
 * $state['tolerance'] of the block before them. Blocks are non-overlapping
 * on purpose: a sliding window that moves one round at a time shares nearly
 * all its samples with the previous check, so it reads as "stable" almost
 * immediately even though barely any new data came in. Comparing whole
 * fresh blocks against each other is a real test of whether the timing has
 * stopped moving.
 *
 * The block statistic is the median, not the mean. A single round can spike
 * by 10-100x when the OS scheduler preempts the process or the GC runs
 * mid-round, and that one round then dominates a mean of 20 - the median
 * shrugs it off, so the stability check tracks the typical round instead of
 * chasing whichever block happened to catch a spike.
 *
 * Keeps taking rounds and recomputing the streak even after
 * $state['converged'] first flips true - it never freezes a method on its
 * own. That flag only tells the caller "this method's threshold has been
 * met at least once"; a round-robin driver should keep stepping every
 * method, converged or not, until all of them report converged at once, so
 * no method's numbers are frozen early while the others are still warming
 * up, and every method's reported average reflects the same stretch of
 * wall-clock time.
 *
 * @param callable(int):void $body
 */
function stepConvergence(array &$state, callable $body, int $iterations): void {
    // The maxRounds cap is the one thing that does freeze a method - it's a
    // safety limit, not a convergence signal, so once hit there is nothing
    // more to measure.
    if ($state['round'] >= $state['maxRounds']) {
        return;
    }

    if ($state['warmupRemaining'] > 0) {
        $seconds = timeRound($body, $iterations);
        $state['lastNs'] = ($seconds / $iterations) * 1e9;
        $state['warmupRemaining']--;
        return;
    }

    $seconds = timeRound($body, $iterations);
    $roundNs = ($seconds / $iterations) * 1e9;
    $state['roundsNs'][] = $roundNs;
    $state['round']++;
    $state['lastNs'] = $roundNs;

    if (count($state['roundsNs']) % $state['window'] === 0) {
        $blockMedian = median(array_slice($state['roundsNs'], -$state['window']));

        if ($state['prevBlockMedian'] !== null) {
            $relChange = abs($blockMedian - $state['prevBlockMedian']) / $state['prevBlockMedian'];
            $state['streak'] = $relChange < $state['tolerance'] ? $state['streak'] + 1 : 0;
        }

        $state['prevBlockMedian'] = $blockMedian;
        $state['blockMedian'] = $blockMedian;
    }

    // Recomputed fresh every round, not latched: if a later round knocks the
    // streak back down, converged should say so, since a round-robin driver
    // is meant to keep this method running until every method's streak
    // holds at the same time.
    $state['converged'] = $state['streak'] >= $state['stableStreak'] || $state['round'] >= $state['maxRounds'];

    if ($state['converged']) {
        // The reported figure is the median of the tail, for the same reason
        // the stability check uses medians: it is what a call actually costs
        // most of the time, undistorted by the rare round a scheduler or GC
        // pause lands in.
        $tailWindow = min($state['window'] * 4, count($state['roundsNs']));
        $state['average'] = median(array_slice($state['roundsNs'], -$tailWindow));
    }
}

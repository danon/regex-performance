<?php
namespace Benchmark\Harness;

use Closure;

/**
 * Finds an iteration count for which one round of a subject takes roughly
 * $targetSeconds of wall-clock time.
 *
 * Hardcoding a fixed iteration count means a 50ns function and a 10ms function
 * get the same $n - one finishes a round in microseconds (dominated by
 * scheduler/timer noise, not the code under test) and the other takes minutes.
 * Calibrating against a duration target instead keeps every subject's round
 * long enough to average out that noise, without the caller having to know each
 * subject's per-op cost ahead of time.
 */
final class Calibrator
{
    public function __construct(
        public readonly float $targetSeconds,
        public readonly int   $seedIterations,
        public readonly int   $maxIterations,
    ) {
    }

    /**
     * Doubles the iteration count from $seedIterations until a round takes at
     * least $targetSeconds, then scales linearly onto the target from that last
     * measurement, since round duration is ~linear in iteration count once
     * JIT/opcode-cache effects have settled during doubling.
     *
     * $onAttempt is called after every doubling round with the iteration count
     * and elapsed seconds just measured, so a caller can show progress instead
     * of appearing to hang - each attempt round can itself take up to
     * $targetSeconds with nothing else happening in between.
     *
     * @param Closure(int):void $body
     * @param Closure(int $iterations, float $elapsedSeconds):void $onAttempt
     */
    public function calibrate(Closure $body, Closure $onAttempt): int {
        $n = $this->seedIterations;
        $elapsed = Timer::timeRound($body, $n);
        $onAttempt($n, $elapsed);

        while ($elapsed < $this->targetSeconds && $n < $this->maxIterations) {
            $n = min($n * 2, $this->maxIterations);
            $elapsed = Timer::timeRound($body, $n);
            $onAttempt($n, $elapsed);
        }

        if ($elapsed <= 0.0) {
            return $n;
        }

        $scaled = (int)round($n * ($this->targetSeconds / $elapsed));

        return max($this->seedIterations, min($scaled, $this->maxIterations));
    }
}

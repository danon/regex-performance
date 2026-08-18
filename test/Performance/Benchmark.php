<?php
namespace Test\Performance;

/**
 * Compares two loop bodies that perform the same work, and reports how much
 * slower the second one is.
 *
 * Each body receives the iteration count and runs its own loop, so the loop
 * overhead is paid identically by both sides and cancels out of the ratio.
 */
final class Benchmark
{
    /**
     * Rounds thrown away before measuring. The first calls pay for PCRE pattern
     * compilation, opcode/function resolution and CPU frequency ramp-up, none
     * of which is what we are comparing.
     */
    private const WARMUP_ROUNDS = 2;

    /** @var int */
    private $iterations;

    /** @var int */
    private $rounds;

    public function __construct(int $iterations, int $rounds)
    {
        $this->iterations = $iterations;
        $this->rounds = $rounds;
    }

    /**
     * @param callable(int):void $baseline
     * @param callable(int):void $candidate
     */
    public function compare(callable $baseline, callable $candidate): Comparison
    {
        for ($round = 0; $round < self::WARMUP_ROUNDS; $round++) {
            $this->time($baseline);
            $this->time($candidate);
        }

        $bestBaseline = PHP_FLOAT_MAX;
        $bestCandidate = PHP_FLOAT_MAX;

        // Interleaved so that drift over the run - thermal throttling, a noisy
        // process starting up - lands on both sides instead of on whichever one
        // happened to be measured second.
        for ($round = 0; $round < $this->rounds; $round++) {
            $bestBaseline = min($bestBaseline, $this->time($baseline));
            $bestCandidate = min($bestCandidate, $this->time($candidate));
        }

        // The minimum, not the mean: scheduler preemption and cache eviction can
        // only ever add time, so the fastest round is the closest estimate of the
        // real cost, and it is far more stable across runs than an average.
        return new Comparison($bestBaseline, $bestCandidate, $this->iterations);
    }

    private function time(callable $body): float
    {
        $iterations = $this->iterations;

        $start = hrtime(true);
        $body($iterations);
        $elapsed = hrtime(true) - $start;

        return $elapsed / 1e9;
    }
}

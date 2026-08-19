<?php
namespace Benchmark\Harness;

/**
 * When a subject's timings count as settled.
 */
final class ConvergenceSettings
{
    /**
     * @param int   $window       Rounds per block. Block medians, not individual
     *                            rounds, are what the stability check compares.
     * @param float $tolerance    Relative change between consecutive block
     *                            medians that still counts as "unchanged".
     * @param int   $stableStreak Consecutive unchanged blocks required.
     * @param int   $maxRounds    Safety cap: stop measuring even if never stable.
     * @param int   $warmupRounds Rounds thrown away before measuring. The first
     *                            calls pay for PCRE pattern compilation, opcode
     *                            and function resolution and CPU frequency
     *                            ramp-up, none of which is under comparison.
     */
    public function __construct(
        public readonly int   $window,
        public readonly float $tolerance,
        public readonly int   $stableStreak,
        public readonly int   $maxRounds,
        public readonly int   $warmupRounds,
    ) {
    }
}

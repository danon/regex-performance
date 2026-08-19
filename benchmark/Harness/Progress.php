<?php
namespace Benchmark\Harness;

/**
 * A read-only snapshot of one subject's run, taken between rounds.
 *
 * Convergence keeps the live state; this is what it hands out to anything that
 * only wants to look at it, such as the terminal table.
 */
final class Progress
{
    public function __construct(
        public readonly int    $round,
        public readonly ?float $lastNs,
        public readonly ?float $blockMedian,
        public readonly int    $streak,
        public readonly int    $stableStreak,
        public readonly ?float $average,
        public readonly bool   $converged,
        public readonly int    $warmupRemaining,
        public readonly int    $warmupTotal,
    ) {
    }

    public function isWarmingUp(): bool {
        return $this->warmupRemaining > 0;
    }
}

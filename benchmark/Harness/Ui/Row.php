<?php
namespace Benchmark\Harness\Ui;

use Benchmark\Harness\Progress;

/**
 * One subject's line in the live table.
 *
 * Built fresh from the run state every redraw rather than mutated in place, so
 * what the table shows is always a consistent picture of one moment.
 */
final class Row
{
    private function __construct(
        public readonly string $name,
        public readonly ?int   $iterations,
        public readonly int    $rounds,
        public readonly ?float $lastNs,
        public readonly ?float $blockMedian,
        public readonly int    $streak,
        public readonly int    $stableStreak,
        public readonly ?float $average,
        public readonly string $status,
    ) {
    }

    /** Before anything has been measured for this subject. */
    public static function pending(string $name): self {
        return new self($name, null, 0, null, null, 0, 0, null, 'pending');
    }

    /** While the iteration count is still being searched for. */
    public static function calibrating(string $name, int $iterations, float $elapsedSeconds): self {
        $status = sprintf('calibrating (%.2fs @ %s iters)', $elapsedSeconds, number_format($iterations));

        return new self($name, $iterations, 0, null, null, 0, 0, null, $status);
    }

    /** Once the iteration count is fixed and rounds are being taken. */
    public static function measuring(string $name, int $iterations, Progress $progress): self {
        return new self(
            $name,
            $iterations,
            $progress->round,
            $progress->lastNs,
            $progress->blockMedian,
            $progress->streak,
            $progress->stableStreak,
            $progress->average,
            self::statusOf($progress),
        );
    }

    private static function statusOf(Progress $progress): string {
        if ($progress->converged) {
            return 'converged';
        }

        if ($progress->isWarmingUp()) {
            return sprintf('warmup %d/%d', $progress->warmupTotal - $progress->warmupRemaining, $progress->warmupTotal);
        }

        return 'running';
    }
}

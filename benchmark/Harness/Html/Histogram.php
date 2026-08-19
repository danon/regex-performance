<?php
namespace Benchmark\Harness\Html;

/**
 * Fixed bin edges spanning a range, so several sets of values can be binned the
 * same way and line up on one chart.
 */
final class Histogram
{
    /**
     * @param float[] $edges Bin boundaries, one more than there are bins.
     */
    private function __construct(public readonly array $edges) {
    }

    /**
     * Bins of equal width covering everything in $values.
     *
     * Degenerate input - every value identical, so the range has no width -
     * collapses to a single bin rather than dividing by zero.
     *
     * @param float[] $values
     */
    public static function spanning(array $values, int $binCount): self {
        $min = min($values);
        $max = max($values);
        $binWidth = ($max - $min) / $binCount;

        if ($binWidth <= 0.0) {
            return new self([$min, $min + 1]);
        }

        $edges = [];
        for ($i = 0; $i <= $binCount; $i++) {
            $edges[] = $min + $i * $binWidth;
        }

        return new self($edges);
    }

    public function binCount(): int {
        return count($this->edges) - 1;
    }

    /**
     * How many of $values fall in each bin. Anything at or above the top edge
     * lands in the last bin rather than being dropped.
     *
     * @param float[] $values
     * @return int[]
     */
    public function counts(array $values): array {
        $binCount = $this->binCount();
        $counts = array_fill(0, $binCount, 0);

        foreach ($values as $value) {
            $bin = $binCount - 1;
            for ($i = 0; $i < $binCount; $i++) {
                if ($value < $this->edges[$i + 1] || $i === $binCount - 1) {
                    $bin = $i;
                    break;
                }
            }
            $counts[$bin]++;
        }

        return $counts;
    }

    /**
     * @return string[] One label per bin, its lower edge.
     */
    public function labels(): array {
        $labels = [];
        for ($i = 0; $i < $this->binCount(); $i++) {
            $labels[] = number_format($this->edges[$i], 1);
        }

        return $labels;
    }
}

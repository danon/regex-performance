<?php
namespace Benchmark\Harness;

final class Statistics
{
    /**
     * The middle value of $values, or the mean of the middle two when there is
     * an even number of them.
     *
     * Used instead of the mean wherever a round timing is summarised. A single
     * round can spike by 10-100x when the OS scheduler preempts the process or
     * the GC runs mid-round, and one such round dominates a mean of twenty; the
     * median shrugs it off and keeps describing the typical round.
     *
     * @param float[] $values
     */
    public static function median(array $values): float {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }
}

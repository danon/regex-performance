<?php
namespace Benchmark\Harness;

use Closure;

final class Timer
{
    /**
     * Wall-clock seconds taken by one call of $body with $iterations.
     *
     * hrtime() rather than microtime(): it is monotonic, so a clock adjustment
     * mid-round cannot turn into a negative or wildly inflated measurement.
     *
     * @param Closure(int):void $body
     */
    public static function timeRound(Closure $body, int $iterations): float {
        $start = hrtime(true);
        $body($iterations);
        $elapsed = hrtime(true) - $start;

        return $elapsed / 1e9;
    }
}

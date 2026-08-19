<?php
namespace Benchmark\Harness\Html;

use Benchmark\Harness\MethodResult;

/**
 * One subject's line in the report's summary table, already worked out against
 * the baseline so the template only has to print it.
 */
final class SummaryRow
{
    private function __construct(
        public readonly string $name,
        public readonly string $color,
        public readonly string $cost,
        public readonly int    $iterations,
        public readonly float  $averageNs,
        public readonly string $delta,
    ) {
    }

    /** The subject everything else is measured against. */
    public static function baseline(MethodResult $method, string $color): self {
        return new self(
            $method->name,
            $color,
            number_format($method->average, 0) . ' ns',
            $method->iterations,
            $method->average,
            '—',
        );
    }

    /**
     * Anything else, shown as the baseline plus what this subject adds - the
     * added cost is the interesting number, and a bare total hides it.
     */
    public static function against(MethodResult $method, MethodResult $baseline, string $color): self {
        return new self(
            $method->name,
            $color,
            sprintf(
                '%s ns + %s ns',
                number_format($baseline->average, 0),
                number_format($method->average - $baseline->average, 0),
            ),
            $method->iterations,
            $method->average,
            sprintf('%+.0f%%', ($method->average / $baseline->average - 1) * 100),
        );
    }
}

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
     * Anything else, shown as the baseline plus or minus what this subject
     * changes - that difference is the interesting number, and a bare total
     * hides it.
     */
    public static function against(MethodResult $method, MethodResult $baseline, string $color): self {
        return new self(
            $method->name,
            $color,
            self::cost($baseline->average, $method->average - $baseline->average),
            $method->iterations,
            $method->average,
            sprintf('%+.0f%%', ($method->average / $baseline->average - 1) * 100),
        );
    }

    /**
     * Not every subject is dearer than the baseline - one that does less work,
     * such as matching against an empty subject, comes out below it. The sign
     * belongs in the operator rather than on the number, so a saving reads as
     * "250 ns - 15 ns" and not as "250 ns + -15 ns".
     *
     * The operator is chosen from the rounded difference, not the raw one, so a
     * subject that lands within half a nanosecond of the baseline cannot print
     * as "- 0 ns".
     */
    private static function cost(float $baselineNs, float $differenceNs): string {
        $rounded = round($differenceNs);

        return sprintf(
            '%s ns %s %s ns',
            number_format($baselineNs, 0),
            $rounded < 0 ? '-' : '+',
            number_format(abs($rounded), 0),
        );
    }
}

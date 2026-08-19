<?php
namespace Benchmark\Harness\Html;

/**
 * One line or bar group on a chart: the subject it belongs to, the colour it is
 * drawn in, and the values plotted.
 */
final class Series
{
    /**
     * @param array<int, int|float> $values
     */
    public function __construct(
        public readonly string $label,
        public readonly string $color,
        public readonly array  $values,
    ) {
    }
}

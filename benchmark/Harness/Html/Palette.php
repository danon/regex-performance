<?php
namespace Benchmark\Harness\Html;

/**
 * The series colours, shared by the charts and the summary table so a method is
 * the same colour wherever it appears in the report.
 *
 * Enough of them that a run does not have to reuse one: two methods sharing a
 * colour on a line chart is worse than an ugly colour.
 */
final class Palette {
    private const COLORS = [
        '#2a78d6', // blue
        '#eb6834', // orange
        '#1baf7a', // green
        '#8b5cf6', // purple
        '#d64545', // red
        '#0f9fb5', // cyan
        '#c08a00', // amber
        '#c2529b', // magenta
    ];

    public function colorAt(int $index): string {
        return self::COLORS[$index % count(self::COLORS)];
    }
}

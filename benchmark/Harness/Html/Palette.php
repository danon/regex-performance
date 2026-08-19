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
        // The vivid hues are used up by here, so the rest are told apart by
        // being darker and duller rather than by hue alone - a ninth vivid
        // colour would only be mistaken for one of the eight above it.
        '#5c6f82', // slate
        '#7f4f24', // brown
        '#6b7a1f', // olive
        '#3d3d5c', // indigo
    ];

    public function colorAt(int $index): string {
        return self::COLORS[$index % count(self::COLORS)];
    }
}

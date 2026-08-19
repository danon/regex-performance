<?php
namespace Benchmark\Harness\Html;

/**
 * The series colours, shared by the charts and the summary table so a subject
 * is the same colour wherever it appears in the report.
 */
final class Palette
{
    private const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#8b5cf6'];

    public function colorAt(int $index): string {
        return self::COLORS[$index % count(self::COLORS)];
    }
}

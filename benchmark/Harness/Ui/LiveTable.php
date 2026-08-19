<?php
namespace Benchmark\Harness\Ui;

/**
 * An in-place terminal table, redrawn by moving the cursor back over the lines
 * it printed last time.
 *
 * Knows nothing about the benchmark beyond the title it prints - it is handed a
 * fresh set of rows and draws them.
 */
final class LiveTable
{
    private const ESC = "\x1b";

    /**
     * A round already takes about as long as the target round length, so
     * redrawing faster than this only costs terminal writes.
     */
    private const MIN_DRAW_INTERVAL_SECONDS = 0.05;

    private int $renderedLines = 0;
    private float $lastDrawAt = 0.0;

    public function __construct(
        private readonly string $title,
        private readonly float  $targetRoundSeconds,
    ) {
    }

    /**
     * Windows terminals need VT100 processing turned on explicitly for ANSI
     * cursor movement; POSIX terminals already support it.
     */
    public static function enableAnsi(): void {
        if (function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
        }
    }

    /**
     * @param Row[] $rows
     */
    public function draw(array $rows): void {
        $lines = $this->format($rows);

        if ($this->renderedLines > 0) {
            $this->moveCursorUp($this->renderedLines);
        }
        foreach ($lines as $line) {
            $this->clearLine();
            echo $line . "\n";
        }

        $this->renderedLines = count($lines);
        $this->lastDrawAt = microtime(true);
    }

    /**
     * Draws only if enough time has passed since the last draw, so a caller can
     * report after every round without the redraw itself becoming the cost.
     *
     * @param Row[] $rows
     */
    public function drawIfDue(array $rows): void {
        if (microtime(true) - $this->lastDrawAt < self::MIN_DRAW_INTERVAL_SECONDS) {
            return;
        }

        $this->draw($rows);
    }

    /**
     * @param Row[] $rows
     * @return string[]
     */
    private function format(array $rows): array {
        $lines = [];
        $lines[] = "{$this->title} (live)";
        $lines[] = 'target round length: ~'
            . number_format($this->targetRoundSeconds, 2)
            . 's (iterations/round calibrated per method)';
        $lines[] = '';
        $lines[] = sprintf(
            '%-34s %12s %8s %14s %14s %10s %14s %s',
            'method',
            'iters/round',
            'rounds',
            'last (ns)',
            'block med (ns)',
            'streak',
            'avg (ns)',
            'status',
        );
        $lines[] = str_repeat('-', 34 + 1 + 12 + 1 + 8 + 1 + 14 + 1 + 14 + 1 + 10 + 1 + 14 + 1 + 10);

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '%-34s %12s %8d %14s %14s %10s %14s %s',
                $row->name,
                $row->iterations !== null ? number_format($row->iterations) : '-',
                $row->rounds,
                $row->lastNs !== null ? number_format($row->lastNs, 1) : '-',
                $row->blockMedian !== null ? number_format($row->blockMedian, 1) : '-',
                $row->streak > 0 ? "{$row->streak}/{$row->stableStreak}" : '-',
                $row->average !== null ? number_format($row->average, 1) : '-',
                $row->status,
            );
        }

        return $lines;
    }

    private function moveCursorUp(int $lines): void {
        if ($lines > 0) {
            echo self::ESC . "[{$lines}A";
        }
    }

    private function clearLine(): void {
        echo self::ESC . "[2K\r";
    }
}

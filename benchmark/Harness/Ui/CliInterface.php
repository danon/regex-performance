<?php
namespace Benchmark\Harness\Ui;

use Benchmark\Harness\Progress;
use RuntimeException;

/**
 * Reports a run as a live terminal table that rewrites itself in place.
 *
 * Holds one Row per subject and replaces whichever one just changed, so the
 * table it draws is always a consistent picture of one moment rather than a
 * mix of states from different rounds.
 */
final class CliInterface implements UserInterface
{
    private ?LiveTable $table = null;

    /** @var Row[] Keyed by subject name, in measurement order. */
    private array $rows = [];

    public function __construct(private readonly string $title) {
    }

    public function begin(array $names, float $targetRoundSeconds): void {
        LiveTable::enableAnsi();

        $this->table = new LiveTable($this->title, $targetRoundSeconds);
        foreach ($names as $name) {
            $this->rows[$name] = Row::pending($name);
        }

        $this->table->draw($this->rows);
    }

    public function calibrating(string $name, int $iterations, float $elapsedSeconds): void {
        $this->rows[$name] = Row::calibrating($name, $iterations, $elapsedSeconds);

        // Drawn unconditionally: calibration attempts are already seconds apart,
        // and each one is the only sign that the run has not stalled.
        $this->table()->draw($this->rows);
    }

    public function progressed(string $name, int $iterations, Progress $progress): void {
        $this->rows[$name] = Row::measuring($name, $iterations, $progress);

        // Throttled, and drawn after each subject's round rather than once per
        // full pass - each round already takes about the target round length on
        // its own, so waiting for every subject before redrawing would multiply
        // the gap between visible updates.
        $this->table()->drawIfDue($this->rows);
    }

    public function finished(): void {
        // The last few rounds were very likely swallowed by the throttle, so
        // draw once more unconditionally to leave the final numbers on screen.
        $this->table()->draw($this->rows);
    }

    private function table(): LiveTable {
        if ($this->table === null) {
            throw new RuntimeException('begin() must be called before anything can be reported.');
        }

        return $this->table;
    }
}

<?php
namespace Benchmark\Harness\Ui;

use Benchmark\Harness\Progress;

/**
 * How a Benchmark reports what it is doing while it runs.
 *
 * A run takes minutes and spends nearly all of it inside a timed loop, so
 * without this it looks like a hang. Keeping it an interface is what lets the
 * same Benchmark drive a live terminal table, a plain log, or nothing at all -
 * and it keeps every echo out of the code that does the measuring, where output
 * would be a cost paid inside the thing being timed.
 */
interface UserInterface
{
    /**
     * Called once, before anything is measured.
     *
     * @param string[] $names Subject names, in the order they will be measured.
     */
    public function begin(array $names, float $targetRoundSeconds): void;

    /** Called after every attempt at finding a subject's iteration count. */
    public function calibrating(string $name, int $iterations, float $elapsedSeconds): void;

    /** Called after every round a subject takes, warmup rounds included. */
    public function progressed(string $name, int $iterations, Progress $progress): void;

    /** Called once, when every subject has converged. */
    public function finished(): void;
}

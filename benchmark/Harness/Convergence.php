<?php
namespace Benchmark\Harness;

use Closure;
use RuntimeException;

/**
 * Drives one subject's rounds one at a time and decides when its timings have
 * stopped moving.
 *
 * Stepping a round at a time rather than running to completion is what lets a
 * caller interleave rounds across several subjects instead of finishing each
 * one before starting the next.
 *
 * Rounds are timed in non-overlapping blocks of $settings->window rounds; the
 * subject is marked converged once $settings->stableStreak consecutive block
 * medians each land within $settings->tolerance of the block before them.
 * Blocks are non-overlapping on purpose: a sliding window that moves one round
 * at a time shares nearly all its samples with the previous check, so it reads
 * as "stable" almost immediately even though barely any new data came in.
 * Comparing whole fresh blocks against each other is a real test of whether the
 * timing has stopped moving.
 */
final class Convergence
{
    private int $warmupRemaining;

    /** @var float[] */
    private array $roundsNs = [];

    private ?float $prevBlockMedian = null;
    private int $streak = 0;
    private int $round = 0;
    private ?float $lastNs = null;
    private ?float $blockMedian = null;
    private bool $converged = false;
    private ?float $average = null;

    public function __construct(private readonly ConvergenceSettings $settings) {
        $this->warmupRemaining = $settings->warmupRounds;
    }

    /**
     * Runs a single warmup or measured round.
     *
     * Keeps taking rounds and recomputing the streak even after the subject has
     * once counted as converged - it never freezes a subject on its own. That
     * flag only says "this subject's threshold has been met at least once"; a
     * round-robin driver should keep stepping every subject, converged or not,
     * until all of them report converged at once, so no subject's numbers are
     * frozen early while the others are still warming up, and every subject's
     * reported figure reflects the same stretch of wall-clock time.
     *
     * @param Closure(int):void $body
     */
    public function step(Closure $body, int $iterations): void {
        // The maxRounds cap is the one thing that does freeze a subject - it is
        // a safety limit, not a convergence signal, so once hit there is nothing
        // more to measure.
        if ($this->round >= $this->settings->maxRounds) {
            return;
        }

        if ($this->warmupRemaining > 0) {
            $seconds = Timer::timeRound($body, $iterations);
            $this->lastNs = ($seconds / $iterations) * 1e9;
            $this->warmupRemaining--;
            return;
        }

        $seconds = Timer::timeRound($body, $iterations);
        $roundNs = ($seconds / $iterations) * 1e9;
        $this->roundsNs[] = $roundNs;
        $this->round++;
        $this->lastNs = $roundNs;

        if (count($this->roundsNs) % $this->settings->window === 0) {
            $this->closeBlock();
        }

        // Recomputed fresh every round, not latched: if a later round knocks the
        // streak back down, this should say so, since a round-robin driver is
        // meant to keep this subject running until every subject's streak holds
        // at the same time.
        $this->converged = $this->streak >= $this->settings->stableStreak
            || $this->round >= $this->settings->maxRounds;

        if ($this->converged) {
            // The reported figure is the median of the tail, for the same reason
            // the stability check uses medians: it is what a call actually costs
            // most of the time, undistorted by the rare round a scheduler or GC
            // pause lands in.
            $tailWindow = min($this->settings->window * 4, count($this->roundsNs));
            $this->average = Statistics::median(array_slice($this->roundsNs, -$tailWindow));
        }
    }

    private function closeBlock(): void {
        $blockMedian = Statistics::median(array_slice($this->roundsNs, -$this->settings->window));

        if ($this->prevBlockMedian !== null) {
            $relChange = abs($blockMedian - $this->prevBlockMedian) / $this->prevBlockMedian;
            $this->streak = $relChange < $this->settings->tolerance ? $this->streak + 1 : 0;
        }

        $this->prevBlockMedian = $blockMedian;
        $this->blockMedian = $blockMedian;
    }

    public function isConverged(): bool {
        return $this->converged;
    }

    public function progress(): Progress {
        return new Progress(
            $this->round,
            $this->lastNs,
            $this->blockMedian,
            $this->streak,
            $this->settings->stableStreak,
            $this->average,
            $this->converged,
            $this->warmupRemaining,
            $this->settings->warmupRounds,
        );
    }

    public function result(string $name, int $iterations): MethodResult {
        if ($this->average === null) {
            throw new RuntimeException("Subject '{$name}' has not converged yet; there is no figure to report.");
        }

        return new MethodResult($name, $this->roundsNs, $this->average, $this->round, $iterations);
    }
}

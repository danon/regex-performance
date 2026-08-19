<?php
namespace Benchmark\Harness;

/**
 * How long a run is allowed to take, and how much agreement it asks for before
 * calling a method settled.
 *
 * A preset is a time budget first and a set of thresholds second. That way the
 * choice offered on the command line is the only one worth making - how long
 * are you willing to wait - and the round count needed to honour it is worked
 * out rather than guessed. Guessing is what makes a "five minute" mode stop
 * taking five minutes the moment a method is added to the run.
 *
 * The budget is a ceiling, not a target. A run that settles early stops early;
 * the tighter tolerances on the longer presets are what make them likely to use
 * most of what they are given.
 */
final class RunPreset
{
    /**
     * @param float $roundSeconds  How long one round of one method should take.
     *                             Longer rounds average out more scheduler and
     *                             timer noise, at a proportional cost in time.
     * @param float $budgetSeconds Ceiling for the whole run, across every method.
     * @param int   $window        Rounds per block; block medians are what the
     *                             stability check compares.
     * @param float $tolerance     Relative change between consecutive block
     *                             medians that still counts as unchanged.
     * @param int   $stableStreak  Consecutive unchanged blocks required.
     * @param int   $warmupRounds  Rounds thrown away before measuring.
     */
    public function __construct(
        public readonly float $roundSeconds,
        public readonly float $budgetSeconds,
        public readonly int   $window,
        public readonly float $tolerance,
        public readonly int   $stableStreak,
        public readonly int   $warmupRounds,
    ) {
    }

    public function convergenceFor(int $methodCount): ConvergenceSettings {
        return new ConvergenceSettings(
            $this->window,
            $this->tolerance,
            $this->stableStreak,
            $this->maxRoundsFor($methodCount),
            $this->warmupRounds,
        );
    }

    /**
     * The round cap that keeps the whole run inside its budget.
     *
     * Methods are measured round-robin, so they share the budget: the more of
     * them there are, the fewer rounds each one gets. Adding a method therefore
     * shortens everyone's share instead of stretching the run past the number
     * in its name.
     *
     * The floor is the fewest rounds that could ever satisfy the stability
     * check - one block to establish a median, then $stableStreak more to agree
     * with it. Below that the run could not converge however long it ran, so a
     * budget too small to reach it loses: better a mode that overruns its name
     * than one that can only ever end by hitting its cap.
     */
    public function maxRoundsFor(int $methodCount): int {
        $affordable = (int)floor($this->budgetSeconds / ($this->roundSeconds * $methodCount)) - $this->warmupRounds;

        return max($this->window * ($this->stableStreak + 1), $affordable);
    }

    /**
     * The shortest this preset can take, in seconds: every method converging at
     * the first opportunity. What it actually takes lands between this and the
     * budget.
     */
    public function fastestSeconds(int $methodCount): float {
        $floor = $this->window * ($this->stableStreak + 1);

        return ($this->warmupRounds + $floor) * $methodCount * $this->roundSeconds;
    }
}

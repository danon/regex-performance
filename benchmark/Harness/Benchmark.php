<?php
namespace Benchmark\Harness;

use Benchmark\Harness\Ui\UserInterface;
use Closure;
use DateTimeImmutable;

/**
 * Measures a set of subjects against each other and reports what each one
 * costs per operation.
 *
 * Knows nothing about what it is measuring: it is handed named loop bodies and
 * hands back a Report. What those bodies do, and what the run is called, belong
 * to whoever calls it.
 */
final class Benchmark
{
    public function __construct(
        private readonly UserInterface       $ui,
        private readonly Calibrator          $calibrator,
        private readonly ConvergenceSettings $convergenceSettings,
    ) {
    }

    /**
     * Runs every subject to convergence and returns the result.
     *
     * @param array<string, callable(int):void> $subjects Name => loop body. Each
     *        body runs its own loop of $n iterations, so the loop overhead is
     *        paid identically by every subject and cancels out when they are
     *        compared. The first one is the baseline the rest are reported
     *        against.
     */
    public function measure(array $subjects): Report {
        $measured = [];
        foreach ($subjects as $name => $body) {
            $measured[] = new Subject($name, Closure::fromCallable($body));
        }

        $this->ui->begin(array_keys($subjects), $this->calibrator->targetSeconds);

        $iterations = $this->calibrateAll($measured);
        $converged = $this->runAll($measured, $iterations);

        $this->ui->finished();

        $results = [];
        foreach ($measured as $subject) {
            $results[$subject->name] = $converged[$subject->name]
                ->result($subject->name, $iterations[$subject->name]);
        }

        return new Report(
            $this->calibrator->targetSeconds,
            PHP_VERSION,
            new DateTimeImmutable(),
            $results,
        );
    }

    /**
     * Calibrates each subject's iteration count separately, so every round
     * lands near the target round length regardless of how cheap or expensive
     * that subject is - a 50ns body and a 10ms body should not share one
     * hardcoded iteration count.
     *
     * @param Subject[] $subjects
     * @return array<string, int>
     */
    private function calibrateAll(array $subjects): array {
        $iterations = [];
        foreach ($subjects as $subject) {
            $onAttempt = function (int $attempted, float $elapsedSeconds) use ($subject): void {
                $this->ui->calibrating($subject->name, $attempted, $elapsedSeconds);
            };

            $iterations[$subject->name] = $this->calibrator->calibrate($subject->body, $onAttempt);
        }

        return $iterations;
    }

    /**
     * Round-robin: one round of each subject per pass, not all of one subject
     * before the next, so they share the same stretch of wall-clock time and
     * any CPU throttling or background noise hits all of them alike.
     *
     * Every subject keeps taking rounds every pass - even ones already past
     * their own streak - because Convergence re-checks the streak fresh each
     * round; only once ALL of them are converged on the same pass does the run
     * stop. Otherwise the first subject to settle would have its numbers frozen
     * while the others were still warming the machine up.
     *
     * @param Subject[] $subjects
     * @param array<string, int> $iterations
     * @return array<string, Convergence>
     */
    private function runAll(array $subjects, array $iterations): array {
        $converged = [];
        foreach ($subjects as $subject) {
            $converged[$subject->name] = new Convergence($this->convergenceSettings);
            $this->ui->progressed(
                $subject->name,
                $iterations[$subject->name],
                $converged[$subject->name]->progress(),
            );
        }

        $allConverged = false;
        while (!$allConverged) {
            $allConverged = true;

            foreach ($subjects as $subject) {
                $running = $converged[$subject->name];
                $running->step($subject->body, $iterations[$subject->name]);

                $this->ui->progressed($subject->name, $iterations[$subject->name], $running->progress());

                if (!$running->isConverged()) {
                    $allConverged = false;
                }
            }
        }

        return $converged;
    }
}

<?php
namespace Test\Performance;

/**
 * The outcome of one Benchmark::compare() run.
 */
final class Comparison
{
    /** @var float Best wall-clock time of the baseline, in seconds. */
    private $baselineSeconds;

    /** @var float Best wall-clock time of the candidate, in seconds. */
    private $candidateSeconds;

    /** @var int Calls performed per round by each side. */
    private $iterations;

    public function __construct(float $baselineSeconds, float $candidateSeconds, int $iterations)
    {
        $this->baselineSeconds = $baselineSeconds;
        $this->candidateSeconds = $candidateSeconds;
        $this->iterations = $iterations;
    }

    /**
     * Relative slowdown of the candidate: 0.0 means identical, 0.10 means the
     * candidate took 10% longer. Negative means the candidate was faster.
     */
    public function loss(): float
    {
        return $this->candidateSeconds / $this->baselineSeconds - 1.0;
    }

    /** Fixed cost the candidate adds per call, in nanoseconds. */
    public function overheadNanoseconds(): float
    {
        return ($this->candidateSeconds - $this->baselineSeconds) / $this->iterations * 1e9;
    }

    public function baselineNanoseconds(): float
    {
        return $this->baselineSeconds / $this->iterations * 1e9;
    }

    public function candidateNanoseconds(): float
    {
        return $this->candidateSeconds / $this->iterations * 1e9;
    }

    public function describe(): string
    {
        return sprintf(
            'baseline %.1f ns/op, candidate %.1f ns/op, overhead %+.1f ns/op, loss %+.2f%% (%d calls per round)',
            $this->baselineNanoseconds(),
            $this->candidateNanoseconds(),
            $this->overheadNanoseconds(),
            $this->loss() * 100,
            $this->iterations
        );
    }
}

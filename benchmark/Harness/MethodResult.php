<?php
namespace Benchmark\Harness;

/**
 * What one subject's completed run is worth reporting: every measured round,
 * and the figure derived from them.
 */
final class MethodResult
{
    /**
     * @param float[] $roundsNs ns/op for each measured round, in order.
     * @param float   $average  ns/op the subject converged on.
     */
    public function __construct(
        public readonly string $name,
        public readonly array  $roundsNs,
        public readonly float  $average,
        public readonly int    $roundsRun,
        public readonly int    $iterations,
    ) {
    }

    /**
     * @return array{rounds: float[], average: float, roundsRun: int, iterations: int}
     */
    public function toArray(): array {
        return [
            'rounds'     => $this->roundsNs,
            'average'    => $this->average,
            'roundsRun'  => $this->roundsRun,
            'iterations' => $this->iterations,
        ];
    }

    /**
     * @param array{rounds: float[], average: float, roundsRun: int, iterations: int} $data
     */
    public static function fromArray(string $name, array $data): self {
        return new self(
            $name,
            $data['rounds'],
            (float)$data['average'],
            (int)$data['roundsRun'],
            (int)$data['iterations'],
        );
    }

    /**
     * The last $count measured rounds, or all of them if fewer were run.
     *
     * @return float[]
     */
    public function tailRounds(int $count): array {
        return array_slice($this->roundsNs, -min($count, count($this->roundsNs)));
    }
}

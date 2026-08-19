<?php
namespace Benchmark\Harness;

use DateTimeImmutable;
use JsonException;

/**
 * A finished benchmark run: what every subject converged on, and the conditions
 * it was measured under.
 *
 * This is the handover between the two halves of the tool. The runner produces
 * one and writes it to disk; the HTML renderer reads one back and turns it into
 * a page, without ever running a benchmark itself.
 *
 * It carries no name of its own - the run is identified by the file it is saved
 * as, so the same subjects can be measured repeatedly and kept side by side.
 */
final class Report
{
    /**
     * @param MethodResult[]        $methods Keyed by subject name, in measurement order.
     * @param array<string, string[]> $groups Group name => the subject names in it, in
     *        display order. Each group is compared against its own first member rather
     *        than the report's overall baseline - what a subject belongs to, and what it
     *        is judged against, is a decision about what is being benchmarked, so it is
     *        made once by whoever defines the subjects and carried through rather than
     *        re-inferred from names when the report is rendered.
     */
    public function __construct(
        public readonly float             $targetRoundSeconds,
        public readonly string            $phpVersion,
        public readonly DateTimeImmutable $generatedAt,
        public readonly array             $methods,
        public readonly array             $groups,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string {
        $methods = [];
        foreach ($this->methods as $result) {
            $methods[$result->name] = $result->toArray();
        }

        return json_encode([
            'target_round_seconds' => $this->targetRoundSeconds,
            'php_version'          => $this->phpVersion,
            'generated_at'         => $this->generatedAt->format('c'),
            'methods'              => $methods,
            'groups'               => $this->groups,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $methods = [];
        foreach ($data['methods'] as $name => $method) {
            $methods[$name] = MethodResult::fromArray($name, $method);
        }

        return new self(
            (float)$data['target_round_seconds'],
            $data['php_version'],
            new DateTimeImmutable($data['generated_at']),
            $methods,
            $data['groups'],
        );
    }

    /**
     * The MethodResult objects belonging to one group, in the group's own
     * display order.
     *
     * @return MethodResult[]
     */
    public function methodsInGroup(string $groupName): array {
        return array_map(
            fn(string $subjectName): MethodResult => $this->methods[$subjectName],
            $this->groups[$groupName],
        );
    }

    /**
     * The subject every other one is compared against: the first one measured.
     */
    public function baseline(): MethodResult {
        return $this->methods[array_key_first($this->methods)];
    }

    public function longestRun(): int {
        return max(array_map(static fn(MethodResult $m): int => count($m->roundsNs), $this->methods));
    }
}

<?php
namespace Benchmark\Harness;

use DateTimeImmutable;
use JsonException;

/**
 * A finished benchmark run: what was measured, on what, and the result for
 * every subject.
 *
 * This is the handover between the two halves of the tool. The live runner
 * produces one and writes it to disk; the HTML renderer reads one back and
 * turns it into a page, without ever running a benchmark itself.
 */
final class Report
{
    /**
     * @param MethodResult[] $methods Keyed by subject name, in measurement order.
     */
    public function __construct(
        public readonly string            $pattern,
        public readonly string            $subject,
        public readonly float             $targetRoundSeconds,
        public readonly string            $phpVersion,
        public readonly DateTimeImmutable $generatedAt,
        public readonly array             $methods,
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
            'pattern'              => $this->pattern,
            'subject'              => $this->subject,
            'target_round_seconds' => $this->targetRoundSeconds,
            'php_version'          => $this->phpVersion,
            'generated_at'         => $this->generatedAt->format('c'),
            'methods'              => $methods,
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
            $data['pattern'],
            $data['subject'],
            (float)$data['target_round_seconds'],
            $data['php_version'],
            new DateTimeImmutable($data['generated_at']),
            $methods,
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

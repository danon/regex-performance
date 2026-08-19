<?php
namespace Benchmark\Harness;

use Closure;

/**
 * One thing being measured: a display name and a loop body that performs the
 * work $iterations times.
 *
 * The body runs its own loop rather than being called once per iteration, so
 * the loop overhead is paid identically by every subject and cancels out when
 * they are compared against each other.
 */
final class Subject
{
    /**
     * @param Closure(int):void $body
     */
    public function __construct(
        public readonly string  $name,
        public readonly Closure $body,
    ) {
    }
}

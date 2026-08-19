<?php
namespace Benchmark\Measured;

use Benchmark\Harness\Subject;

/**
 * The subjects under benchmark, and nothing else.
 *
 * Everything measured lives in this directory; the harness that times it does
 * not appear here, and nothing here knows how it is being timed. Adding a call
 * shape to the run means adding it below, not touching the runner.
 *
 * Each body assigns its result to a variable that is never read. That is
 * deliberate and identical across all four: dropping the assignment in some
 * bodies and not others would compare loops that do different amounts of work.
 */
final class CallShapes
{
    /**
     * @return Subject[] Baseline first - every other subject is reported
     *                   relative to it.
     */
    public static function all(string $pattern, string $subject): array {
        return [
            new Subject(
                'plain (inline preg_match)',
                static function (int $n) use ($pattern, $subject): void {
                    for ($i = 0; $i < $n; $i++) {
                        $matched = preg_match($pattern, $subject) === 1;
                    }
                },
            ),
            new Subject(
                'preg_test (library wrapper)',
                static function (int $n) use ($pattern, $subject): void {
                    for ($i = 0; $i < $n; $i++) {
                        $matched = preg_test($pattern, $subject);
                    }
                },
            ),
            new Subject(
                'matchOnce (1 preg_match call)',
                static function (int $n) use ($pattern, $subject): void {
                    for ($i = 0; $i < $n; $i++) {
                        $matched = matchOnce($pattern, $subject);
                    }
                },
            ),
            new Subject(
                'matchThrice (3 preg_match calls)',
                static function (int $n) use ($pattern, $subject): void {
                    for ($i = 0; $i < $n; $i++) {
                        $matched = matchThrice($pattern, $subject);
                    }
                },
            ),
        ];
    }
}

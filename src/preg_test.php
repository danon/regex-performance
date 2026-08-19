<?php

/**
 * Tests whether $subject matches $pattern, without capturing anything.
 *
 * Equivalent to `preg_match($pattern, $subject) === 1`, but says what it means
 * at the call site and returns a bool instead of an int|false.
 *
 * No parameter or return types are declared on purpose. The body is a single
 * preg_match() call, so the per-call type checks are a measurable share of the
 * total cost (~10ns of ~20ns of wrapper overhead). Nothing is validated less
 * strictly: preg_match() itself is typed, so a non-string argument is rejected
 * there instead of being rejected twice. The wrapper is one of the call shapes
 * the benchmark prices - run `php benchmark/cli.php` to see what it costs.
 *
 * @param string $pattern
 * @param string $subject
 * @return bool
 */
function preg_test($pattern, $subject)
{
    return (bool)preg_match($pattern, $subject);
}

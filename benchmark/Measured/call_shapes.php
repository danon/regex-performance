<?php
/**
 * Call shapes that exist only to be measured.
 *
 * Two userland functions differing only in how many preg_match() calls they
 * make. The gap between them is two matches with no call overhead in it, which
 * prices a preg_match() from inside userland; the gap between matchOnce() and
 * an inline preg_match() prices the userland call itself. Between them they say
 * what preg_test() is made of.
 *
 * Global functions, not methods, because that is the shape preg_test() has:
 * a static method call and a plain function call do not cost the same, and the
 * difference is a visible share of what is being measured here.
 */

function matchOnce(string $pattern, string $subject): bool {
    return preg_match($pattern, $subject) === 1;
}

/**
 * The results are collected before they are combined: `&&` would short circuit
 * on a subject that does not match and only two calls, or one, would actually
 * run.
 */
function matchThrice(string $pattern, string $subject): bool {
    $first = preg_match($pattern, $subject) === 1;
    $second = preg_match($pattern, $subject) === 1;
    $third = preg_match($pattern, $subject) === 1;

    return $first && $second && $third;
}

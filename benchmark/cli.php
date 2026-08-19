<?php
/**
 * Live TUI benchmark runner.
 *
 * Runs the three call-shape benchmarks interleaved, round-robin - one round
 * of each method in turn, not all of one method before starting the next -
 * so they share the same stretch of wall-clock time and any CPU throttling
 * or background noise hits all of them alike. Redraws an in-place terminal
 * table with each method's round count, latest round time, block-median
 * streak, and (once converged) its average. Writes
 * benchmark/report.json when done. Rendering the HTML report is a separate
 * step - see benchmark/render.php.
 */

require __DIR__ . '/lib.php';

// Windows terminals need VT100 processing turned on explicitly for ANSI
// cursor movement; POSIX terminals already support it.
if (function_exists('sapi_windows_vt100_support')) {
    @sapi_windows_vt100_support(STDOUT, true);
}

const ESC = "\x1b";

function moveCursorUp(int $lines): void {
    if ($lines > 0) {
        echo ESC . "[{$lines}A";
    }
}

function clearLine(): void {
    echo ESC . "[2K\r";
}

/**
 * @param array<string, array{rounds: int, iterations: int, lastNs: ?float, blockMedian: ?float, streak: int, stableStreak: int, average: ?float, status: string}> $state
 */
function renderTable(array $state, string $pattern, string $subject, float $targetRoundSeconds, int $renderedLines): int {
    $lines = [];
    $lines[] = "preg_match() call-shape benchmark (live)";
    $lines[] = "pattern: {$pattern}   subject: {$subject}   target round length: ~" . number_format($targetRoundSeconds, 1) . 's (iterations/round calibrated per method)';
    $lines[] = "";
    $lines[] = sprintf("%-34s %12s %8s %14s %14s %10s %14s %s", 'method', 'iters/round', 'rounds', 'last (ns)', 'block med (ns)', 'streak', 'avg (ns)', 'status');
    $lines[] = str_repeat('-', 34 + 1 + 12 + 1 + 8 + 1 + 14 + 1 + 14 + 1 + 10 + 1 + 14 + 1 + 10);

    foreach ($state as $name => $s) {
        $lines[] = sprintf(
            "%-34s %12s %8d %14s %14s %10s %14s %s",
            $name,
            $s['iterations'] > 0 ? number_format($s['iterations']) : 'calibrating',
            $s['rounds'],
            $s['lastNs'] !== null ? number_format($s['lastNs'], 1) : '-',
            $s['blockMedian'] !== null ? number_format($s['blockMedian'], 1) : '-',
            $s['streak'] > 0 ? "{$s['streak']}/{$s['stableStreak']}" : '-',
            $s['average'] !== null ? number_format($s['average'], 1) : '-',
            $s['status'],
        );
    }

    if ($renderedLines > 0) {
        moveCursorUp($renderedLines);
    }
    foreach ($lines as $line) {
        clearLine();
        echo $line . "\n";
    }

    return count($lines);
}

$pattern = '/^[\w.+-]+@[\w-]+\.[\w.]{2,}$/';
$subject = 'daniel.wilkowski@example.co.uk';
// Override with BENCH_TARGET_SECONDS=0.05 php benchmark/cli.php for a quick
// smoke test; the real benchmark should use the ~1s default.
$targetRoundSeconds = (float) (getenv('BENCH_TARGET_SECONDS') ?: 1.0);

$methods = benchmarkMethods($pattern, $subject);

$state = [];
foreach (array_keys($methods) as $name) {
    $state[$name] = [
        'rounds'       => 0,
        'iterations'   => 0,
        'lastNs'       => null,
        'blockMedian'  => null,
        'streak'       => 0,
        'stableStreak' => 0,
        'average'      => null,
        'status'       => 'pending',
    ];
}

$renderedLines = 0;
$lastDrawAt = 0.0;
$minDrawIntervalSeconds = 0.05;

$redraw = static function () use (&$state, &$renderedLines, $pattern, $subject, $targetRoundSeconds): void {
    $renderedLines = renderTable($state, $pattern, $subject, $targetRoundSeconds, $renderedLines);
};

$redraw();

// Calibrate each method's iteration count separately so every round lands
// near $targetRoundSeconds regardless of how cheap or expensive that
// method's call shape is - a 50ns function and a slower one shouldn't share
// one hardcoded iteration count.
$calibrationSeedIterations = 1000;
$calibrationMaxIterations = 100_000_000;

$iterationsByName = [];
foreach ($methods as $name => $body) {
    $state[$name]['status'] = 'calibrating';
    $redraw();

    $iterationsByName[$name] = calibrateIterations(
        $body,
        $targetRoundSeconds,
        $calibrationSeedIterations,
        $calibrationMaxIterations
    );

    $state[$name]['iterations'] = $iterationsByName[$name];
    $state[$name]['status'] = 'running';
    $redraw();
}

$window = 20;
$tolerance = 0.01;
$stableStreak = 4;
$maxRounds = 3000;
$warmup = 10;

$convergence = [];
foreach (array_keys($methods) as $name) {
    $convergence[$name] = newConvergenceState($window, $tolerance, $stableStreak, $maxRounds, $warmup);
}

// Round-robin: one round of each method per pass, not all of one method
// before the next, so they share the same stretch of wall-clock time. Every
// method keeps taking rounds every pass - even ones already past their own
// streak - because stepConvergence() re-checks the streak fresh each round;
// only once ALL of them are converged on the same pass does the run stop.
$allConverged = false;
while (!$allConverged) {
    $allConverged = true;

    foreach ($methods as $name => $body) {
        stepConvergence($convergence[$name], $body, $iterationsByName[$name]);

        $c = $convergence[$name];
        $state[$name]['rounds'] = $c['round'];
        $state[$name]['lastNs'] = $c['lastNs'];
        $state[$name]['blockMedian'] = $c['blockMedian'];
        $state[$name]['streak'] = $c['streak'];
        $state[$name]['stableStreak'] = $c['stableStreak'];
        $state[$name]['status'] = $c['converged'] ? 'converged' : 'running';
        $state[$name]['average'] = $c['average'];

        if (!$c['converged']) {
            $allConverged = false;
        }
    }

    $now = microtime(true);
    if ($now - $lastDrawAt >= $minDrawIntervalSeconds || $allConverged) {
        $lastDrawAt = $now;
        $redraw();
    }
}

$results = [];
foreach (array_keys($methods) as $name) {
    $results[$name] = [
        'rounds'     => $convergence[$name]['roundsNs'],
        'average'    => $convergence[$name]['average'],
        'roundsRun'  => $convergence[$name]['round'],
        'iterations' => $iterationsByName[$name],
    ];
}

$output = [
    'pattern'               => $pattern,
    'subject'               => $subject,
    'target_round_seconds'  => $targetRoundSeconds,
    'php_version'           => PHP_VERSION,
    'generated_at'          => date('c'),
    'methods'               => $results,
];

$outFile = __DIR__ . '/report.json';
file_put_contents($outFile, json_encode($output, JSON_PRETTY_PRINT));

echo "\nWrote {$outFile}\n";
echo "Run `php benchmark/render.php` to build the HTML report.\n";

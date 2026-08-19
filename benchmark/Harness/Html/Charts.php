<?php
namespace Benchmark\Harness\Html;

use Benchmark\Harness\MethodResult;
use Benchmark\Harness\Report;

/**
 * Turns a finished Report into Chart.js configurations.
 *
 * Only shapes data - it does not fetch anything. What renders the configs is
 * QuickChart's problem, and what the resulting images are used for is
 * HtmlReport's.
 */
final class Charts
{
    public function __construct(private readonly Palette $palette) {
    }

    /**
     * ns/op for every measured round, one line per subject.
     *
     * QuickChart's free tier caps total chart data points (~500), so each
     * subject's rounds are downsampled to $pointBudget by taking every Nth
     * round rather than dropping the tail, which would hide whether and when it
     * converged.
     *
     * @return array<string, mixed>
     */
    public function convergence(Report $report, int $pointBudget): array {
        $longestRun = $report->longestRun();
        $stride = max(1, (int)ceil($longestRun / $pointBudget));

        $series = [];
        $index = 0;
        foreach ($report->methods as $method) {
            $series[] = new Series(
                $method->name,
                $this->palette->colorAt($index),
                array_map(static fn(float $ns): float => round($ns, 1), $this->everyNth($method->roundsNs, $stride)),
            );
            $index++;
        }

        $labels = [];
        for ($round = 0; $round < $longestRun; $round += $stride) {
            $labels[] = $round + 1;
        }

        return [
            'type'    => 'line',
            'data'    => [
                'labels'   => $labels,
                'datasets' => array_map(
                    static fn(Series $s): array => [
                        'label'           => $s->label,
                        'data'            => $s->values,
                        'borderColor'     => $s->color,
                        'backgroundColor' => $s->color,
                        'borderWidth'     => 2,
                        'pointRadius'     => 0,
                        'fill'            => false,
                        'tension'         => 0,
                    ],
                    $series,
                ),
            ],
            'options' => [
                'plugins' => [
                    'legend' => ['display' => true, 'position' => 'top'],
                    'title'  => ['display' => true, 'text' => 'Round-by-round convergence (ns/op)'],
                ],
                'scales'  => [
                    'x' => ['title' => ['display' => true, 'text' => 'round #'], 'ticks' => ['maxTicksLimit' => 12]],
                    'y' => ['title' => ['display' => true, 'text' => 'ns/op']],
                ],
            ],
        ];
    }

    /**
     * How the last $tailRounds rounds of each subject were distributed.
     *
     * All subjects are binned against one set of edges spanning every value, so
     * their bars line up and can be read against each other.
     *
     * @return array<string, mixed>
     */
    public function distribution(Report $report, int $tailRounds, int $binCount): array {
        $tails = [];
        foreach ($report->methods as $method) {
            $tails[$method->name] = $method->tailRounds($tailRounds);
        }

        $histogram = Histogram::spanning(array_merge(...array_values($tails)), $binCount);

        $series = [];
        $index = 0;
        foreach ($report->methods as $method) {
            $series[] = new Series(
                $method->name,
                $this->palette->colorAt($index),
                $histogram->counts($tails[$method->name]),
            );
            $index++;
        }

        return [
            'type'    => 'bar',
            'data'    => [
                'labels'   => $histogram->labels(),
                'datasets' => array_map(
                    static fn(Series $s): array => [
                        'label'              => $s->label,
                        'data'               => $s->values,
                        'backgroundColor'    => $s->color,
                        'barPercentage'      => 0.9,
                        'categoryPercentage' => 0.9,
                    ],
                    $series,
                ),
            ],
            'options' => [
                'plugins' => [
                    'legend' => ['display' => true, 'position' => 'top'],
                    'title'  => ['display' => true, 'text' => 'Distribution of round timings'],
                ],
                'scales'  => [
                    'x' => ['title' => ['display' => true, 'text' => 'ns/op'], 'ticks' => ['maxTicksLimit' => 10]],
                    'y' => ['title' => ['display' => true, 'text' => 'rounds'], 'beginAtZero' => true],
                ],
            ],
        ];
    }

    /**
     * @param float[] $values
     * @return float[]
     */
    private function everyNth(array $values, int $stride): array {
        $sampled = [];
        for ($i = 0; $i < count($values); $i += $stride) {
            $sampled[] = $values[$i];
        }

        return $sampled;
    }
}

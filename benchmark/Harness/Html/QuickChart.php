<?php
namespace Benchmark\Harness\Html;

use JsonException;
use RuntimeException;

/**
 * Renders Chart.js configs to PNG via QuickChart (https://quickchart.io/), a
 * hosted Chart.js renderer.
 *
 * Charts are fetched as images and embedded as data URIs rather than drawn by a
 * script in the page, so the report stays a single file that opens with no
 * network access.
 */
class QuickChart {
    public function __construct(private readonly int $timeoutSeconds) {}

    /**
     * @param array<string, mixed> $chartConfig
     * @throws JsonException
     */
    public function renderPng(array $chartConfig, int $width, int $height): string {
        $payload = json_encode([
            'chart'            => $chartConfig,
            'width'            => $width,
            'height'           => $height,
            'devicePixelRatio' => 2.0,
            'backgroundColor'  => 'white',
            'format'           => 'png',
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('http://quickchart.io/chart');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            throw new RuntimeException("QuickChart request failed (HTTP {$status}): {$error}");
        }
        return $body;
    }

    public static function toDataUri(string $pngBytes): string {
        return 'data:image/png;base64,' . base64_encode($pngBytes);
    }
}

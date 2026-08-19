<?php
namespace Benchmark\Harness;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The directory runs are kept in, addressed by name.
 *
 * Naming a run rather than overwriting one fixed file is what lets several be
 * kept side by side - before and after a change, one PHP version and the next.
 */
final class Reports
{
    public function __construct(public readonly string $directory) {
    }

    /**
     * @throws JsonException
     */
    public function save(Report $report, string $name): string {
        $path = $this->jsonPath($name);
        $this->write($path, $report->toJson());

        return $path;
    }

    /**
     * @throws JsonException
     */
    public function load(string $name): Report {
        $path = $this->jsonPath($name);
        if (!is_file($path)) {
            throw new RuntimeException("No report named '{$name}' at {$path}.");
        }

        return Report::fromJson(file_get_contents($path));
    }

    public function saveHtml(string $html, string $name): string {
        $path = $this->htmlPath($name);
        $this->write($path, $html);

        return $path;
    }

    public function jsonPath(string $name): string {
        return $this->directory . '/' . $this->validate($name) . '.json';
    }

    public function htmlPath(string $name): string {
        return $this->directory . '/' . $this->validate($name) . '.html';
    }

    private function write(string $path, string $contents): void {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Could not create {$this->directory}.");
        }

        file_put_contents($path, $contents);
    }

    /**
     * The name reaches this class straight from the command line and is about
     * to become part of a path, so anything that could walk out of the reports
     * directory is refused rather than sanitised into something the caller did
     * not ask for.
     */
    private function validate(string $name): string {
        if (preg_test('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            return $name;
        }

        throw new InvalidArgumentException(
            "Report name '{$name}' must start with a letter or digit and contain only letters, digits, dots, dashes and underscores.",
        );
    }
}

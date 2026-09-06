<?php
/**
 * Enforce a minimum line coverage percentage from a Clover report.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

$report = $argv[1] ?? 'coverage.xml';
$minimum = isset($argv[2]) ? (float) $argv[2] : 80.0;

if (!is_readable($report)) {
    fwrite(STDERR, "Coverage report not found: {$report}\n");
    exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "Invalid Clover coverage report: {$report}\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$coverage = $statements === 0 ? 100.0 : ($covered / $statements) * 100;

printf("Line coverage: %.2f%% (%d/%d), required: %.2f%%\n", $coverage, $covered, $statements, $minimum);
if ($coverage + 0.00001 < $minimum) {
    exit(1);
}

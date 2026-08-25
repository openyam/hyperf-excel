<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: check-coverage.php <clover.xml> <baseline.json>\n");
    exit(2);
}

[$script, $cloverPath, $baselinePath] = $argv;
unset($script);

if (! is_file($cloverPath) || ! is_readable($cloverPath)) {
    fwrite(STDERR, sprintf("Coverage report [%s] is not readable.\n", $cloverPath));
    exit(2);
}
if (! is_file($baselinePath) || ! is_readable($baselinePath)) {
    fwrite(STDERR, sprintf("Coverage baseline [%s] is not readable.\n", $baselinePath));
    exit(2);
}

try {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, sprintf("Coverage baseline is invalid JSON: %s\n", $exception->getMessage()));
    exit(2);
}

$minimum = $baseline['line'] ?? null;
if (! is_int($minimum) && ! is_float($minimum)) {
    fwrite(STDERR, "Coverage baseline must contain a numeric [line] percentage.\n");
    exit(2);
}
if ($minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "Coverage baseline [line] must be between 0 and 100.\n");
    exit(2);
}

$document = new DOMDocument();
if (! @$document->load($cloverPath, LIBXML_NONET)) {
    fwrite(STDERR, sprintf("Coverage report [%s] is invalid XML.\n", $cloverPath));
    exit(2);
}
$metrics = (new DOMXPath($document))->query('/coverage/project/metrics')->item(0);
if (! $metrics instanceof DOMElement) {
    fwrite(STDERR, "Coverage report does not contain project metrics.\n");
    exit(2);
}

$statements = (int) $metrics->getAttribute('statements');
$covered = (int) $metrics->getAttribute('coveredstatements');
if ($statements <= 0 || $covered < 0 || $covered > $statements) {
    fwrite(STDERR, "Coverage report contains invalid statement metrics.\n");
    exit(2);
}

$coverage = $covered / $statements * 100;
printf("Line coverage: %.2f%% (%d/%d), baseline: %.2f%%\n", $coverage, $covered, $statements, $minimum);
if ($coverage + PHP_FLOAT_EPSILON < $minimum) {
    fwrite(STDERR, "Line coverage is below the committed baseline.\n");
    exit(1);
}

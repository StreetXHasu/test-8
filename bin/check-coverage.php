<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/check-coverage.php var/coverage.xml\n");
    exit(1);
}

$xml = simplexml_load_file($argv[1]);
if (false === $xml) {
    fwrite(STDERR, "Cannot read coverage report\n");
    exit(1);
}

$statements = 0;
$covered = 0;
$uncovered = [];

foreach ($xml->xpath('//file') ?: [] as $file) {
    foreach ($file->line as $line) {
        if ('stmt' !== (string) $line['type']) {
            continue;
        }
        ++$statements;
        if ((int) $line['count'] > 0) {
            ++$covered;
            continue;
        }
        $uncovered[] = $file['name'].':'.$line['num'];
    }
}

if (0 === $statements || [] !== $uncovered) {
    fwrite(STDERR, sprintf("Line coverage %d/%d\n", $covered, $statements));
    foreach ($uncovered as $location) {
        fwrite(STDERR, $location."\n");
    }
    exit(1);
}

echo sprintf("Line coverage 100%% (%d/%d)\n", $covered, $statements);

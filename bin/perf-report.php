#!/usr/bin/env php
<?php

declare(strict_types=1);

// Summarize a phpbench dump (dev only — bin/ never ships).
//
//   php bin/perf-report.php [tmp/perf/bench.xml] [--limit=256]
//
// Writes the CSV next to the dump (bench.csv) and prints the steps where time
// or memory grew faster than the collection, plus any request whose peak
// memory would not fit under the host's memory_limit (BSH default 256M).

use Tests\Performance\PerfReport;

require dirname(__DIR__) . '/vendor/autoload.php';

$args    = array_values(array_filter(array_slice($argv, 1), fn (string $a): bool => !str_starts_with($a, '--')));
$options = getopt('', ['limit:']);
$dump    = $args[0] ?? dirname(__DIR__) . '/tmp/perf/bench.xml';
$limit   = (int)($options['limit'] ?? 256);

if (!is_file($dump)) {
	fwrite(STDERR, "No benchmark dump at {$dump}. Run: composer run bench\n");
	exit(1);
}

$report = PerfReport::fromXml((string)file_get_contents($dump));
$csv    = preg_replace('/\.xml$/', '', $dump) . '.csv';
file_put_contents($csv, $report->toCsv());
echo "CSV: {$csv}\n\n";

// "Warm" means Total CMS's cache, which is APCu only while an index fits in
// apc.shm_size; a bigger one silently falls back to the file cache.
printf("APCu: %s, apc.shm_size=%s (indexes bigger than this are cached on disk instead)\n\n", extension_loaded('apcu') ? 'loaded' : 'not loaded', ini_get('apc.shm_size') ?: 'n/a');

$flags = $report->superlinear();
echo $flags === [] ? "No superlinear growth.\n" : "Grows faster than size:\n";
foreach ($flags as $f) {
	printf("  %-45s %6d → %-6d size ×%-6s time ×%-7s mem ×%s\n", $f['subject'], $f['from'], $f['to'], $f['sizeRatio'], $f['timeRatio'], $f['memRatio']);
}

$over = $report->overMemoryLimit($limit);
echo "\n" . ($over === [] ? "Nothing over {$limit} MB.\n" : "Over the {$limit} MB memory_limit:\n");
foreach ($over as $row) {
	printf("  %-45s %-8s %7.1f MB\n", $row['bench'] . '::' . $row['subject'], $row['size'], $row['mem_peak_mb']);
}

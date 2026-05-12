<?php

/**
 * Job Processor — backward-compatible wrapper.
 *
 * This script now delegates to `resources/bin/tcms jobs:process`.
 * All original functionality (lock file, stuck job recovery, import optimization,
 * maintenance, verbose output) is preserved in the CLI command.
 *
 * Usage: php resources/bin/processJobs.php [-v|--verbose] [-m|--memory=1G]
 */
if (php_sapi_name() !== 'cli') {
	echo "This script can only be run from the command line.\n";
	exit(1);
}

$options = getopt('vm:', ['verbose', 'memory:']);

$verbose     = isset($options['v']) || isset($options['verbose']);
$memoryLimit = $options['m'] ?? $options['memory'] ?? null;
if (is_array($memoryLimit)) {
	$memoryLimit = end($memoryLimit);
}

// Build the tcms command
$tcmsBin = __DIR__ . '/tcms';
$args    = ['php', $tcmsBin, 'jobs:process'];

if ($verbose) {
	$args[] = '-v';
}
if (is_string($memoryLimit)) {
	$args[] = '--memory=' . escapeshellarg($memoryLimit);
}

$command = implode(' ', $args);

// Pass through to tcms CLI
$returnCode = 0;
passthru($command, $returnCode);
exit($returnCode);

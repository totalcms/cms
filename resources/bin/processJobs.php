<?php

// Increase memory limit for large collection processing
ini_set('memory_limit', '512M');

// Enable all error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (php_sapi_name() !== 'cli') {
	echo "This script can only be run from the command line.\n";
	exit(1);
}

$lockFilePath = __DIR__ . '/.processJobs';
$lockFile     = fopen($lockFilePath, 'c');
if ($lockFile === false) {
	echo "Error: Unable to open lock file.\n";
	exit(1);
}

// Try to acquire an exclusive lock
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
	echo "Process already running.\n";
	fclose($lockFile);
	exit(1);
}

// Write the current process ID to the lock file
ftruncate($lockFile, 0); // Clear the file
fwrite($lockFile, strval(getmypid()));

// Register a shutdown function to release the lock and close the file
register_shutdown_function(function () use ($lockFile, $lockFilePath) {
	// Check for fatal errors
	$error = error_get_last();
	if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
		echo "\nFatal Error: {$error['message']}\n";
		echo "File: {$error['file']}:{$error['line']}\n";
	}

	flock($lockFile, LOCK_UN); // Release the lock
	fclose($lockFile);        // Close the file
	@unlink($lockFilePath);   // Optionally delete the lock file
});

// Determine DOCUMENT_ROOT from CLI argument
$options = getopt('d:vm:', ['docroot:', 'verbose', 'memory:']);
if (!empty($options['d'])) {
	$docroot = $options['d'];
} elseif (!empty($options['docroot'])) {
	$docroot = $options['docroot'];
} elseif (!empty($argv[1]) && $argv[1][0] !== '-') {
	$docroot = $argv[1];
} else {
	echo "Usage: php {$argv[0]} --docroot=/path/to/docroot [-v|--verbose] [-m|--memory=1G]\n";
	exit(1);
}
if (is_array($docroot)) {
	$docroot = implode('', $docroot);
}

$verbose = isset($options['v']) || isset($options['verbose']);

// Override memory limit if specified
$memoryLimit = $options['m'] ?? $options['memory'] ?? null;
if ($memoryLimit !== null) {
	if (is_array($memoryLimit)) {
		$memoryLimit = end($memoryLimit);
	}
	ini_set('memory_limit', $memoryLimit);
}

$docroot                  = rtrim($docroot, DIRECTORY_SEPARATOR);
$_SERVER['DOCUMENT_ROOT'] = $docroot;

if (!is_dir($docroot)) {
	echo "Error: The specified document root does not exist.\n";
	exit(1);
}

require_once __DIR__ . '/../../autoload.php';

/**
 * Print a formatted table row.
 *
 * @param array<string,int|string> $data
 */
function printTableRow(array $data, int $labelWidth = 15, int $valueWidth = 8): void
{
	foreach ($data as $label => $value) {
		printf("  %-{$labelWidth}s %{$valueWidth}s\n", $label . ':', $value);
	}
}

/**
 * Print a horizontal separator.
 */
function printSeparator(int $width = 50): void
{
	echo str_repeat('-', $width) . "\n";
}

/**
 * Output with flush for real-time display.
 */
function output(string $message): void
{
	echo $message;
	if (ob_get_level() > 0) {
		ob_flush();
	}
	flush();
}

output("\n");
printSeparator();
output("Total CMS Job Processor\n");
printSeparator();

if ($verbose) {
	output('Memory limit: ' . ini_get('memory_limit') . "\n");
}

$startTime = microtime(true);

try {
	$totalcms  = new TotalCMS\TotalCMS();
	$totalcms->disableCache();
	$jobRunner = $totalcms->jobRunner();

	// Reset any stuck in-progress jobs first
	$stuckCount = $jobRunner->resetStuckJobs();
	if ($stuckCount > 0) {
		output("\nRecovered {$stuckCount} stuck job(s) from previous crash.\n");
	}

	// Show initial queue status
	$initialStatus = $jobRunner->getQueueStatus();
	$initialByType = $jobRunner->getQueueByType();

	output("\nInitial Queue Status:\n");
	printTableRow($initialStatus);

	if ($verbose && array_sum($initialByType) > 0) {
		output("\nJobs by Type:\n");
		printTableRow($initialByType);
	}

	// Check if there are any jobs to process
	if ($initialStatus['Total'] === 0) {
		output("\nNo jobs in queue.\n");
		printSeparator();
		exit(0);
	}

	output("\n");
	printSeparator();

	// Retry failed jobs first
	output("Retrying failed jobs...\n");
	$retryStats = $jobRunner->retryFailedJobsWithStats();

	if ($retryStats['total_failed'] > 0) {
		output("  Failed jobs found: {$retryStats['total_failed']}\n");
		output("  Retried:           {$retryStats['retried']}\n");
		output("  Skipped (max attempts): {$retryStats['skipped']}\n");
	} else {
		output("  No failed jobs to retry.\n");
	}

	output("\n");
	printSeparator();
	output("Processing pending jobs...\n");

	// Enable import optimization (defers index rebuilding until all imports are done)
	$optimizedCollections = $jobRunner->enableImportOptimization();
	if (count($optimizedCollections) > 0 && $verbose) {
		output('  Optimizing ' . count($optimizedCollections) . " collection(s) for bulk import\n");
	}

	// Track processing stats
	$processed        = 0;
	$succeeded        = 0;
	$failed           = 0;
	$jobsByCollection = [];
	$jobsByType       = [];

	// Process jobs one by one with verbose output
	while ($jobRunner->hasPendingJobs()) {
		if ($verbose) {
			output("  Processing next job...\n");
		}

		$result = $jobRunner->processNextJobWithDetails();

		if ($result === null) {
			break;
		}

		$processed++;
		$job        = $result['job'];
		$collection = $job['collection'] ?? 'unknown';
		$type       = $job['type'] ?? 'unknown';

		// Track stats
		$jobsByCollection[$collection] = ($jobsByCollection[$collection] ?? 0) + 1;
		$jobsByType[$type]             = ($jobsByType[$type] ?? 0) + 1;

		if ($result['success']) {
			$succeeded++;
			if ($verbose) {
				output(sprintf("  [OK]   #%-5d %-10s %s\n", $job['id'], $type, $collection));
			}
		} else {
			$failed++;
			$error = $result['error'] ?? 'Unknown error';
			if ($verbose) {
				output(sprintf("  [FAIL] #%-5d %-10s %s\n", $job['id'], $type, $collection));
				output('         Error: ' . substr($error, 0, 60) . (strlen($error) > 60 ? '...' : '') . "\n");
			}
		}

		// Show progress every 100 jobs in non-verbose mode
		if (!$verbose && $processed % 100 === 0) {
			output("  Processed {$processed} jobs...\n");
		}
	}

	// Finalize import optimization (rebuild indexes for optimized collections)
	if (count($optimizedCollections) > 0) {
		if ($verbose) {
			output("\n  Finalizing " . count($optimizedCollections) . " collection(s) (rebuilding indexes)...\n");
		}
		$jobRunner->finalizeImportOptimization($optimizedCollections);
	}

	output("\n");
	printSeparator();
	output("Processing Summary\n");
	printSeparator();

	output("\nResults:\n");
	printTableRow([
		'Total Processed' => $processed,
		'Succeeded'       => $succeeded,
		'Failed'          => $failed,
	]);

	if ($verbose && count($jobsByType) > 0) {
		output("\nProcessed by Type:\n");
		ksort($jobsByType);
		printTableRow($jobsByType);
	}

	if ($verbose && count($jobsByCollection) > 0) {
		output("\nProcessed by Collection:\n");
		ksort($jobsByCollection);
		printTableRow($jobsByCollection, 25);
	}

	// Show final queue status
	$finalStatus = $jobRunner->getQueueStatus();
	output("\nFinal Queue Status:\n");
	printTableRow($finalStatus);

	// Run database maintenance (prune old failed jobs and vacuum)
	output("\n");
	printSeparator();
	output("Running jobqueue maintenance...\n");
	$maintenance = $jobRunner->maintenance(30);
	if ($maintenance['pruned'] > 0) {
		output("  Pruned {$maintenance['pruned']} failed job(s) older than 30 days\n");
	}
	output("  Jobqueue vacuumed to reclaim disk space\n");
} catch (Throwable $e) {
	output("\nError: " . $e->getMessage() . "\n");
	output('File: ' . $e->getFile() . ':' . $e->getLine() . "\n");
	if ($verbose) {
		output("Stack trace:\n" . $e->getTraceAsString() . "\n");
	}
	exit(1);
}

$endTime       = microtime(true);
$executionTime = $endTime - $startTime;
$peakMemory    = memory_get_peak_usage(true);

output("\n");
printSeparator();
output(sprintf("Completed in %.2f seconds\n", $executionTime));
if ($verbose) {
	output(sprintf("Peak memory: %.1f MB\n", $peakMemory / 1024 / 1024));
}
printSeparator();
output("\n");

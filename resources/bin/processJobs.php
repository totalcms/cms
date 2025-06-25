<?php

error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

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
	flock($lockFile, LOCK_UN); // Release the lock
	fclose($lockFile);        // Close the file
	unlink($lockFilePath);    // Optionally delete the lock file
});

// Determine DOCUMENT_ROOT from CLI argument
$options = getopt('d:', ['docroot:']);
if (!empty($options['d'])) {
	$docroot = $options['d'];
} elseif (!empty($options['docroot'])) {
	$docroot = $options['docroot'];
} elseif (!empty($argv[1])) {
	$docroot = $argv[1];
} else {
	echo "Usage: php {$argv[0]} --docroot=/path/to/docroot\n";
	exit(1);
}
if (is_array($docroot)) {
	$docroot = implode('', $docroot);
}

$docroot                  = rtrim($docroot, DIRECTORY_SEPARATOR);
$_SERVER['DOCUMENT_ROOT'] = $docroot;

if (!is_dir($docroot)) {
	echo "Error: The specified document root does not exist.\n";
	exit(1);
}

require_once __DIR__ . '/../../autoload.php';

echo "Starting job processing...\n";
$startTime = microtime(true);

try {
	$totalcms = new TotalCMS\TotalCMS();
	$totalcms->jobRunner()->processPendingJobs();
} catch (Exception $e) {
	echo 'Error: ' . $e->getMessage() . "\n";
	exit(1);
}

echo "Job processing completed successfully.\n";
$endTime       = microtime(true);
$executionTime = $endTime - $startTime;
echo 'Execution time: ' . round($executionTime, 2) . " seconds\n";

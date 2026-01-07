<?php

/**
 * Total CMS Diagnostic Script.
 *
 * This file bypasses the Slim router to run diagnostics directly.
 * Access via: https://yoursite.com/diagnose.php?token=YOUR_TOKEN
 */

// Simple token protection - uses current year
define('DIAGNOSE_TOKEN', 'tcms-support-' . date('Y'));

// Verify token before proceeding
if (!isset($_GET['token']) || $_GET['token'] !== DIAGNOSE_TOKEN) {
	http_response_code(404);
	exit;
}

// Prevent search engine indexing
header('X-Robots-Tag: noindex, nofollow, noarchive');

// Set the TCMS base directory (parent of public/)
define('TCMS_BASE_DIR', dirname(__DIR__));

// Load the actual diagnostic script from resources/support
$diagnosticScript = TCMS_BASE_DIR . '/resources/support/diagnose.php';

if (file_exists($diagnosticScript)) {
	require $diagnosticScript;
} else {
	header('Content-Type: text/plain; charset=utf-8');
	echo "ERROR: Diagnostic script not found at: $diagnosticScript\n";
	echo "Please ensure Total CMS is properly installed.\n";
}

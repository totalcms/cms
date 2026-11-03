<?php

/**
 * Total CMS Diagnostic Script.
 *
 * This file bypasses the Slim router to run diagnostics directly, for when
 * the admin will not load at all.
 *
 * To switch it on, create an empty file named `.tcms-support` next to this
 * one (FTP or the host's file manager). The report is then served at
 * https://yoursite.com/diagnose.php for 72 hours from when the file was
 * created; delete the file to switch it off sooner. Without the file the
 * script answers 404.
 *
 * Being able to create a file here is the proof of access: anyone who can
 * can already read everything the report shows. This replaced a
 * `?token=tcms-support-<year>` check, which anyone could guess (security
 * advisory GHSA-3wpr-fj3p-fgr2 finding 2, 2026-11-03).
 */
define('DIAGNOSE_FLAG', __DIR__ . '/.tcms-support');
define('DIAGNOSE_WINDOW', 72 * 3600);

// The file's change time, not its modification time: an FTP client can
// carry an old mtime over from the uploader's computer, but the inode change
// time is always when the file landed here, and cannot be set back.
clearstatcache(true, DIAGNOSE_FLAG);
$flagSince = is_file(DIAGNOSE_FLAG) ? filectime(DIAGNOSE_FLAG) : false;

if ($flagSince === false || time() - $flagSince > DIAGNOSE_WINDOW) {
	if ($flagSince !== false) {
		@unlink(DIAGNOSE_FLAG); // expired: tidy up so it is not left behind
	}
	http_response_code(404);
	exit;
}

define('DIAGNOSE_EXPIRES_AT', $flagSince + DIAGNOSE_WINDOW);

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

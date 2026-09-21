#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Golden snapshots must be in git, or the golden tests prove nothing.
 *
 * Pest never fails a test whose snapshot is missing: it writes the current
 * output as the new baseline and marks the test incomplete. Its `--ci` flag
 * does not change that. So on a fresh clone or a CI runner an ignored or
 * forgotten snapshot would record whatever the code does today and compare
 * nothing — the suite stays green while a form quietly changes.
 *
 * This check fails when a snapshot exists that git does not track (a test
 * created it: add it, or delete it and the case), or when a tracked snapshot
 * was deleted without the deletion being staged (the next run would recreate
 * it silently). Modified snapshots are fine: that is a reviewable diff.
 *
 * Runs after the PHP tests in bin/runtests.sh, in `composer test:build`
 * (CI), and in the pre-commit hook. Exit 0 = clean, 1 = something to commit.
 */
$root = dirname(__DIR__);
$dir  = 'tests/.pest/snapshots';

if (!is_dir($root . '/' . $dir)) {
	exit(0);
}

exec('cd ' . escapeshellarg($root) . ' && git status --porcelain -- ' . escapeshellarg($dir) . ' 2>/dev/null', $lines, $status);
if ($status !== 0) {
	// Not a git checkout (a dist install): nothing to enforce.
	exit(0);
}

$untracked = [];
$deleted   = [];
foreach ($lines as $line) {
	$code = substr($line, 0, 2);
	$path = substr($line, 3);
	if ($code === '??') {
		$untracked[] = $path;
	} elseif ($code === ' D') {
		$deleted[] = $path;
	}
}

if ($untracked === [] && $deleted === []) {
	exit(0);
}

fwrite(STDERR, "\nGolden snapshots are out of step with git.\n\n");
if ($untracked !== []) {
	fwrite(STDERR, '  Created by a test run but not tracked (' . count($untracked) . "):\n");
	foreach (array_slice($untracked, 0, 10) as $path) {
		fwrite(STDERR, "    $path\n");
	}
	if (count($untracked) > 10) {
		fwrite(STDERR, '    … and ' . (count($untracked) - 10) . " more\n");
	}
	fwrite(STDERR, "\n  A new golden case has no baseline until its snapshot is committed. Review\n");
	fwrite(STDERR, "  the file, then `git add $dir`; or delete it with the case.\n\n");
}
if ($deleted !== []) {
	fwrite(STDERR, '  Deleted but the deletion is not staged (' . count($deleted) . "):\n");
	foreach (array_slice($deleted, 0, 10) as $path) {
		fwrite(STDERR, "    $path\n");
	}
	fwrite(STDERR, "\n  The next test run would recreate these silently from the current output.\n");
	fwrite(STDERR, "  Stage the deletion if the case is gone, or restore the file.\n\n");
}

exit(1);

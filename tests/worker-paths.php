<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Per-worker path isolation for parallel test runs.
//
// Paratest (used by `pest --parallel`) forks one worker per core and sets
// TEST_TOKEN to a distinct value in each. Without isolation every worker shares
// /tests/tcms-data/, /cache/ and /tmp/, so they race on the same files —
// producing "Collection for Schema not found" and, because MCP sessions live in
// `tmpdir/mcp-sessions` (config/container.php), "Session not found or has
// expired".
//
// Each worker therefore gets its own sandbox, and all three live together under
// ONE directory: {base}/{token}/{tcms-data,cache,tmp}. Keeping them nested
// matters — a flat layout meant 12 workers scattered 36 top-level `cache-N` /
// `tmp-N` / `tcms-data-N` directories through the project.
//
// {base} is in the system's temp folder, NOT in the project (it was
// /tests/.workers until 2026-11-25). Inside the project, the editor's file
// watcher and the system's file-event daemon reacted to every file a test
// wrote, and together took about two cores from a 12-worker run: the suite ran
// in 101s with the sandboxes outside the project against 114s inside it. There
// is one {base} per checkout, so two copies of the repo never share sandboxes.
// `composer run test:clean` removes it; TCMS_TEST_WORKERS_DIR names another
// place for it.
//
// A serial run has no TEST_TOKEN and keeps the original paths untouched.
//
// Required by tests/bootstrap.php, tests/Pest.php and config/local.test.php —
// all three must agree on the same paths, so they all come through here.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('tcmsTestWorkerToken')) {
	/**
	 * '' for a serial run, else paratest's per-worker token.
	 */
	function tcmsTestWorkerToken(): string
	{
		$token = getenv('TEST_TOKEN');
		if ($token === false || $token === '') {
			$token = $_SERVER['TEST_TOKEN'] ?? '';
		}

		return (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$token);
	}

	/**
	 * This worker's sandbox root, or '' when running serially.
	 */
	function tcmsTestWorkerRoot(): string
	{
		$token = tcmsTestWorkerToken();

		return $token === '' ? '' : tcmsTestWorkersBase() . '/' . $token;
	}

	/**
	 * The folder every worker's sandbox is kept in: outside the project, one
	 * per checkout. See the note at the top of this file.
	 */
	function tcmsTestWorkersBase(): string
	{
		$named = getenv('TCMS_TEST_WORKERS_DIR');
		if (is_string($named) && $named !== '') {
			return rtrim($named, '/');
		}

		// The real path: on macOS the temp folder is reached through a link
		// (/var → /private/var), and code that compares resolved paths must
		// be given the resolved one.
		$temp = realpath(sys_get_temp_dir());

		return ($temp === false ? sys_get_temp_dir() : $temp) . '/totalcms-tests-' . substr(md5(dirname(__DIR__)), 0, 8);
	}

	/**
	 * The mutable data sandbox for this process. Never shared between workers.
	 */
	function tcmsTestDataDir(): string
	{
		$root = tcmsTestWorkerRoot();

		return $root === '' ? dirname(__DIR__) . '/tests/tcms-data' : $root . '/tcms-data';
	}

	/**
	 * The cache dir for this process. A shared Twig/config cache is exactly as
	 * racy as a shared data dir.
	 */
	function tcmsTestCacheDir(): string
	{
		$root = tcmsTestWorkerRoot();

		return $root === '' ? dirname(__DIR__) . '/cache' : $root . '/cache';
	}

	/**
	 * The tmp dir for this process. MCP sessions live in `tmpdir/mcp-sessions`,
	 * so sharing this makes workers clobber each other's sessions.
	 */
	function tcmsTestTmpDir(): string
	{
		$root = tcmsTestWorkerRoot();

		return $root === '' ? dirname(__DIR__) . '/tmp' : $root . '/tmp';
	}
}

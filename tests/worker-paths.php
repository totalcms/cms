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
// ONE hidden directory: /tests/.workers/{token}/{tcms-data,cache,tmp}. Keeping
// them nested matters — a flat layout meant 12 workers scattered 36 top-level
// `cache-N` / `tmp-N` / `tcms-data-N` directories through the project.
// Everything a parallel run creates can now be removed with a single
// `rm -rf tests/.workers`.
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

		return (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $token);
	}

	/**
	 * This worker's sandbox root, or '' when running serially.
	 */
	function tcmsTestWorkerRoot(): string
	{
		$token = tcmsTestWorkerToken();

		return $token === '' ? '' : dirname(__DIR__) . '/tests/.workers/' . $token;
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

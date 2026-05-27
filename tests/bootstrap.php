<?php

declare(strict_types=1);

// may need to increase memory limit for tests in php.ini
ini_set('memory_limit', '1G');

// Set session save path to writable directory for CI environments
$sessionPath = sys_get_temp_dir() . '/php_sessions';
if (!is_dir($sessionPath)) {
	mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);

require_once __DIR__ . '/../vendor/autoload.php';

// Define ROOT for CakePHP I18n translations (resources/locales/)
if (!defined('ROOT')) {
	define('ROOT', dirname(__DIR__));
}

// ─────────────────────────────────────────────────────────────────────────────
// Test fixture restoration
//
// /tests/tcms-data/ is the runtime sandbox: fully gitignored, mutated by
// individual test runs. /tests/tcms-data-fixtures/ holds the canonical
// fixtures (auth users + access-groups.json) tracked in git.
//
// Copy fixtures into the live sandbox ONCE at bootstrap time. This catches
// tests that don't explicitly call recursiveDelete() in their setup — they
// silently rely on auth/.system being present. Tests that DO call
// recursiveDelete() get the fixtures restored again as part of that wipe
// (see tests/Pest.php::restoreFixtures), so both paths converge on the
// same known-good state.
//
// Runs on every test process start, including CI's fresh-clone scenario.
// Idempotent — overwrites existing files if rerun.
// ─────────────────────────────────────────────────────────────────────────────
(function (): void {
	$src = __DIR__ . '/tcms-data-fixtures';
	$dst = __DIR__ . '/tcms-data';

	if (!is_dir($src)) {
		return;
	}

	$copy = function (string $src, string $dst) use (&$copy): void {
		if (!is_dir($dst)) {
			mkdir($dst, 0777, true);
		}
		foreach (scandir($src) as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$s = $src . DIRECTORY_SEPARATOR . $item;
			$d = $dst . DIRECTORY_SEPARATOR . $item;
			if (is_dir($s)) {
				$copy($s, $d);
			} else {
				copy($s, $d);
			}
		}
	};

	$copy($src, $dst);
})();

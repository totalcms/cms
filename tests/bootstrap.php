<?php

declare(strict_types=1);

// Mark the test environment via real APP_ENV provenance. BundleMiddleware (and
// anything else gated on appEnv) keys off this; with it set, the bundle
// integrity check is skipped during tests so the suite no longer needs to
// regenerate resources/bundle first. Pest sets this in tests/Pest.php too; this
// covers the phpunit bootstrap path.
$_SERVER['APP_ENV'] = 'test';

// Tests need more headroom than a stock php.ini gives. This runs after
// PHPUnit has applied phpunit.xml's <ini> block, so a plain ini_set() here
// wins over both that and any -d flag — which is what made
// test:coverage:parallel die in CoverageMerger: the coverage scripts asked
// for more and every worker silently clamped back to 1G. Honour an explicit
// request instead. The env var reaches workers, which php.ini flags do not.
ini_set('memory_limit', getenv('TCMS_TEST_MEMORY_LIMIT') ?: '1G');

// Set session save path to writable directory for CI environments
$sessionPath = sys_get_temp_dir() . '/php_sessions';
if (!is_dir($sessionPath)) {
	mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/worker-paths.php';

// Publish the per-worker paths so config/local.test.php resolves to the same
// sandbox this bootstrap prepares.
$_SERVER['TCMS_TEST_DATADIR']  = tcmsTestDataDir();
$_SERVER['TCMS_TEST_CACHEDIR'] = tcmsTestCacheDir();
$_SERVER['TCMS_TEST_TMPDIR']   = tcmsTestTmpDir();

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
	$dst = tcmsTestDataDir();

	if (is_dir($src)) {
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
	}

	// Generate OAuth signing keys if missing. The OAuth server's
	// AuthorizationServer + ResourceServer are constructed at every /api/*
	// request via OAuthBearerMiddleware's DI; the constructor calls
	// League\OAuth2\Server\CryptKey which throws "Invalid key supplied" if
	// the key file is missing. Locally this can be masked by leftover dev
	// keys; CI starts clean and needs these generated up front.
	// Paths must match $settings['oauth']['signingKeyPath'] from
	// config/local.test.php.
	$keyDir = $dst . '/.system/oauth-keys';
	if (!file_exists($keyDir . '/private.key') || !file_exists($keyDir . '/public.key')) {
		if (!is_dir($keyDir)) {
			mkdir($keyDir, 0700, true);
		}
		$res = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'private_key_bits' => 2048,
		]);
		if ($res !== false) {
			openssl_pkey_export($res, $privKey);
			file_put_contents($keyDir . '/private.key', $privKey);
			chmod($keyDir . '/private.key', 0600);

			$details = openssl_pkey_get_details($res);
			if (is_array($details) && isset($details['key'])) {
				file_put_contents($keyDir . '/public.key', $details['key']);
				chmod($keyDir . '/public.key', 0644);
			}
		}
	}
})();

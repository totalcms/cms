<?php

$_SERVER['APP_ENV'] = 'test';

require_once __DIR__ . '/worker-paths.php';

// Ensure Symfony Console Resources directory exists (missing in some CI environments)
$consoleResourcesDir = dirname((new ReflectionClass(Symfony\Component\Console\Application::class))->getFileName()) . '/Resources';
if (!is_dir($consoleResourcesDir)) {
	@mkdir($consoleResourcesDir, 0755, true);
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function bootstrap()
{
	// Tests reset state by wiping the data dir (recursiveDelete) and rebuilding
	// the app, but APCu lives in shared memory for the WHOLE php process and is
	// not touched by a filesystem wipe. When APCu is enabled (the test scripts
	// pass `-d apc.enable_cli=1`, making it CacheManager's L1 backend) that lets
	// cached collections/objects/indexes leak across tests — causing
	// order-dependent, flaky failures ("object already exists", X-Total 0, etc.).
	// Clearing it on every app boot makes the reset complete so each test starts
	// from a clean cache. No-op when APCu isn't loaded.
	if (function_exists('apcu_clear_cache')) {
		apcu_clear_cache();
	}

	// Session state leaks the same way: after a request the middleware
	// write-closes the session, so session_status() is NONE and the
	// "session_destroy() if active" idiom in test files never fires — the
	// session FILE survives, and because the process keeps the same session id,
	// the next request's session_start() resumes it and resurrects auth keys
	// written by an earlier test file (e.g. the OAuth feature tests logging in
	// as admin) into the next file's "unauthenticated" requests. Destroy any
	// active session, clear the superglobal, and reset the session id so a
	// stale file can never be resumed.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$_SESSION = [];
	session_id('');

	return require __DIR__ . '/../config/bootstrap.php';
}

function testDataDir(): string
{
	return __DIR__ . '/test-data/';
}

function testData(string $file): string
{
	return __DIR__ . '/test-data/' . $file;
}

function cmsDataDir(): string
{
	return tcmsTestDataDir() . '/';
}

function templatePath(string $id, ?string $folder = null): string
{
	$basePath = cmsDataDir() . 'builder/';
	if ($folder !== null && $folder !== '') {
		$basePath .= $folder . '/';
	}

	return $basePath . $id . '.twig';
}

function designerMetaPath(string $id, ?string $folder = null): string
{
	$basePath = cmsDataDir() . 'builder/';
	if ($folder !== null && $folder !== '') {
		$basePath .= $folder . '/';
	}

	return $basePath . $id . '.designer.json';
}

function collectionPath(string $collection): string
{
	return cmsDataDir() . "$collection/";
}

function metaPath(string $collection): string
{
	return cmsDataDir() . "$collection/.meta.json";
}

function schemaPath(string $id): string
{
	return cmsDataDir() . ".schemas/$id.json";
}

function reservedSchemaPath(): string
{
	return __DIR__ . '/../resources/schemas/';
}

function reservedTemplatePath(): string
{
	return __DIR__ . '/../resources/templates/';
}

function jumpstartResourcePath(string $file = ''): string
{
	return __DIR__ . '/../resources/jumpstart/' . $file;
}

function indexPath(string $collection): string
{
	return cmsDataDir() . "$collection/.index.json";
}

function objectPath(string $collection, string $id): string
{
	return cmsDataDir() . "$collection/$id.json";
}

function objectFilesPath(string $collection, string $id): string
{
	return cmsDataDir() . "$collection/$id";
}

function recursiveDelete(string $dir, array $preserve = [], bool $forceComplete = false)
{
	$isRootDataDir = rtrim($dir, '/') === rtrim(cmsDataDir(), '/');

	// Wipe pass — only if the directory actually exists. A non-existent root
	// data dir on a fresh clone (CI, new checkout) still needs the fixture
	// restore below, so we don't short-circuit here.
	if (file_exists($dir)) {
		if (!is_dir($dir)) {
			return unlink($dir);
		}

		foreach (scandir($dir) as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			// Skip explicitly-preserved entries (caller-supplied opt-in only).
			if (in_array($item, $preserve, true)) {
				continue;
			}

			if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item, [], $forceComplete)) {
				return false;
			}
		}

		// Don't remove the root tcms-data dir itself — only its contents.
		if (!$isRootDataDir) {
			return rmdir($dir);
		}
	}

	// Restore checked-in fixtures (auth users, .system/access-groups.json,
	// etc.) so tests start from a known state every time. Fixtures live at
	// /tests/tcms-data-fixtures/ as the source of truth; the whole
	// /tests/tcms-data/ tree is gitignored. Runs even when the root dir
	// didn't exist beforehand — recursiveCopy() creates it.
	// $forceComplete suppresses the restore — used when a test genuinely
	// needs a pre-fixture state (e.g. setup-wizard tests).
	if ($isRootDataDir && !$forceComplete) {
		restoreFixtures();
	}

	return true;
}

/**
 * Whether $dir's filesystem reflects an explicit chmod(0600) back through
 * fileperms(). Some CI filesystems carry a default POSIX ACL, so the group
 * bits in st_mode show the ACL mask (0644) even after chmod(0600) — meaning a
 * 0600 assertion is unverifiable there, not that the code failed to chmod.
 * Permission tests probe the directory they actually write to and skip the
 * octal assertion when it returns false, so they still catch a real
 * regression (chmod works in the dir, but the code didn't apply it).
 */
function chmodReflectsPrivateMode(string $dir): bool
{
	$probe = $dir . '/.tcms-chmod-probe-' . uniqid();
	if (@file_put_contents($probe, 'x') === false) {
		return false;
	}

	@chmod($probe, 0600);
	clearstatcache(true, $probe);
	$reflects = substr(sprintf('%o', fileperms($probe)), -4) === '0600';
	@unlink($probe);

	return $reflects;
}

/**
 * Copy every file under /tests/tcms-data-fixtures/ into the live test data
 * dir. Called by recursiveDelete() at root-dir cleanup so every test run
 * starts with the canonical fixtures (auth users, access-groups.json, etc.)
 * in place. Idempotent — safe to call standalone if you need to restore
 * fixtures without first wiping the dir.
 */
function restoreFixtures(): void
{
	$src = __DIR__ . '/tcms-data-fixtures';
	if (!is_dir($src)) {
		return;
	}
	recursiveCopy($src, rtrim(cmsDataDir(), '/'));
}

/**
 * Build a real, dev-environment ExtensionGuard for use in tests.
 *
 * Env is 'dev' so auto-quarantine never fires (quarantine is destructive and
 * prod-only). Collaborators are real (not PHPUnit mocks) so this helper works
 * from BOTH Pest closures AND class-based PHPUnit TestCases — test() resolves
 * differently in the two contexts, and createMock() outside a TestCase trips
 * Pest into treating the call as a test description.
 *
 * On the guard's success path it touches nothing; in dev a throw only logs
 * (NullLogger) and bumps the in-memory counter. This lets ExtensionManager
 * tests pass a genuine guard with a single inserted constructor argument.
 */
function testExtensionGuard(): TotalCMS\Domain\Extension\Service\ExtensionGuard
{
	$config      = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$env         = new TotalCMS\Domain\Extension\Service\EnvironmentResolver($config, false);

	// In-memory CacheManager subclass — overrides the two methods the guard's
	// failure counter uses and skips the heavy 11-dependency parent constructor.
	$cache = new class extends TotalCMS\Domain\Cache\CacheManager {
		/** @var array<string,mixed> */
		private array $store = [];

		public function __construct()
		{
			// Intentionally bypass parent — the guard never touches the cache
			// services, only getData()/storeData().
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function getOperationalData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};

	// Real state repo over a shared temp dir (no PHPUnit mock needed).
	// A single directory is created once and reused across all calls; a
	// shutdown function removes it when the test process exits so it does
	// not accumulate in the system temp directory.
	static $tmpRoot = null;
	if ($tmpRoot === null) {
		$tmpRoot = sys_get_temp_dir() . '/tcms-test-guard-' . bin2hex(random_bytes(6));
		@mkdir($tmpRoot, 0777, true);
		register_shutdown_function(function () use ($tmpRoot): void {
			if (is_dir($tmpRoot)) {
				recursiveDelete($tmpRoot, forceComplete: true);
			}
		});
	}
	$flysystem = new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($tmpRoot));
	$storage   = new TotalCMS\Domain\Storage\StorageFilesystemAdapter($flysystem);
	$repo      = new TotalCMS\Domain\Extension\Repository\ExtensionStateRepository($storage);

	return new TotalCMS\Domain\Extension\Service\ExtensionGuard(
		$env,
		$cache,
		$repo,
		new Psr\Log\NullLogger(),
		testExtensionProfiler(),
	);
}

/**
 * Build a real, dev-environment ExtensionProfiler for use in tests.
 *
 * Env is 'dev' so shouldSurfaceErrors() is true and the profiler always
 * profiles (no sampling), giving deterministic timing in tests. Collaborators
 * are real (not PHPUnit mocks) so this works from both Pest closures and
 * class-based TestCases — mirroring testExtensionGuard().
 */
function testExtensionProfiler(): TotalCMS\Domain\Extension\Service\ExtensionProfiler
{
	$config      = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$env         = new TotalCMS\Domain\Extension\Service\EnvironmentResolver($config, false);

	$cache = new class extends TotalCMS\Domain\Cache\CacheManager {
		/** @var array<string,mixed> */
		private array $store = [];

		public function __construct()
		{
			// Intentionally bypass parent — the profiler only uses getData()/storeData().
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function getOperationalData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};

	return new TotalCMS\Domain\Extension\Service\ExtensionProfiler(
		$env,
		$cache,
		1,
		new Psr\Log\NullLogger(),
	);
}

/**
 * Copy a directory tree from $src to $dst, creating dirs as needed and
 * overwriting existing files. Helper for restoreFixtures().
 */
function recursiveCopy(string $src, string $dst): void
{
	if (!is_dir($dst)) {
		mkdir($dst, 0777, true);
	}
	foreach (scandir($src) as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$srcPath = $src . DIRECTORY_SEPARATOR . $item;
		$dstPath = $dst . DIRECTORY_SEPARATOR . $item;
		if (is_dir($srcPath)) {
			recursiveCopy($srcPath, $dstPath);
		} else {
			copy($srcPath, $dstPath);
		}
	}
}

/**
 * A private data dir for one test, safe to use under --parallel.
 *
 * Test files used to build these as `sys_get_temp_dir() . '/prefix-' . uniqid()`.
 * uniqid() is derived from the clock (seconds + microseconds) with no
 * per-process entropy, so two workers entering setUp() in the same microsecond
 * get the SAME directory. Measured across 8 concurrent processes, 16000
 * uniqid() calls produced 3928 distinct values. When it collided, one file's
 * setUp()/tearDown() deleted state another was mid-test on.
 *
 * The worker token makes the path unique across processes and random_bytes()
 * makes it unique within one, so neither half depends on the clock. The
 * directory is removed at shutdown if the test left it empty.
 */
function tcmsTestTempDir(string $prefix): string
{
	try {
		$suffix = bin2hex(random_bytes(8));
	} catch (Random\RandomException) {
		$suffix = bin2hex((string)getmypid()) . bin2hex((string)mt_rand());
	}

	$token = tcmsTestWorkerToken();
	$token = $token === '' ? 'solo' : $token;

	$dir = sys_get_temp_dir() . '/' . $prefix . '-' . $token . '-' . getmypid() . '-' . $suffix;

	// Nothing used to remove these: this machine had 7280 leftovers from past
	// runs. Clean up our own at shutdown, matched by the prefix we wrote.
	static $dirs = [];
	if ($dirs === []) {
		register_shutdown_function(static function () use (&$dirs): void {
			foreach ($dirs as $created) {
				foreach ((array)@scandir($created . '/.system') as $entry) {
					if ($entry !== '.' && $entry !== '..') {
						@unlink($created . '/.system/' . $entry);
					}
				}
				@rmdir($created . '/.system');
				@rmdir($created); // left alone if the test put anything else in it
			}
		});
	}
	$dirs[] = $dir;

	return $dir;
}

/** A private data dir for one dev-mode test. See tcmsTestTempDir(). */
function devModeDataDir(): string
{
	return tcmsTestTempDir('tcms-devmode');
}

/**
 * Build a DevModeManager rooted at the given data dir. The state file lives at
 * `<datadir>/.system/totalcms_devmode.json` — per-install, never the shared
 * global /tmp path (which collides across tenants on shared hosting). Pass the
 * same $datadir to two calls to exercise shared-file state across managers.
 */
function devModeManager(string $datadir): TotalCMS\Domain\Cache\Service\DevModeManager
{
	$config          = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;

	if (!is_dir($config->systemDir())) {
		@mkdir($config->systemDir(), 0775, true);
	}

	return new TotalCMS\Domain\Cache\Service\DevModeManager(
		new TotalCMS\Domain\Event\Service\EventDispatcher(new Psr\Log\NullLogger()),
		$config,
	);
}

/** The per-install dev-mode state file path for a given data dir. */
function devModeFile(string $datadir): string
{
	return $datadir . '/.system/totalcms_devmode.json';
}

/**
 * Build a CSRFRequestValidator wired to a real origin validator.
 *
 * The CSRF policy is "same origin OR valid token", so every consumer needs both
 * halves. Requests without Origin/Referer land on the token path, which is what
 * the token-focused suites exercise; pass a $domain matching the request's
 * Origin to exercise the same-origin path instead.
 */
function csrfValidatorFor(
	TotalCMS\Domain\Security\CSRF\CSRFTokenManager $manager,
	string $domain = 'tests.local',
): TotalCMS\Domain\Security\CSRF\CSRFRequestValidator {
	$config         = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->domain = $domain;

	return new TotalCMS\Domain\Security\CSRF\CSRFRequestValidator(
		$manager,
		new TotalCMS\Domain\Security\CSRF\RequestOriginValidator($config),
	);
}

/**
 * Mock HTTP client returning a fixed response.
 *
 * Lives here rather than in a test file because two suites need it
 * (LicenseValidatorHttpTest and TemplateDesignerSyncTest). A global
 * function declared inside one *Test.php file is only visible to another
 * when both happen to load into the same process — true for a serial run,
 * false under `pest --parallel`, where the two files can land in different
 * workers and the call fails with "undefined function".
 */
function createMockHttpClient(
	TotalCMS\Support\HttpResponse $response,
): TotalCMS\Support\HttpClientInterface {
	$client = test()->createMock(TotalCMS\Support\HttpClientInterface::class);
	$client->method('request')->willReturn($response);

	return $client;
}

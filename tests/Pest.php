<?php

$_SERVER['APP_ENV'] = 'test';

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
	// not touched by a filesystem wipe. Under php-test.ini (which enables APCu
	// as CacheManager's L1 backend) that lets cached collections/objects/indexes
	// leak across tests — causing order-dependent, flaky failures ("object
	// already exists", X-Total 0, etc.). Clearing it on every app boot makes the
	// reset complete so each test starts from a clean cache. No-op when APCu
	// isn't loaded (default php.ini).
	if (function_exists('apcu_clear_cache')) {
		apcu_clear_cache();
	}

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
	return __DIR__ . '/tcms-data/';
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

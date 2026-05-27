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
	if (!file_exists($dir)) {
		return true;
	}

	if (!is_dir($dir)) {
		return unlink($dir);
	}

	$isRootDataDir = rtrim($dir, '/') === rtrim(cmsDataDir(), '/');

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

	// Don't remove the root tcms-data dir itself. Restore checked-in fixtures
	// (auth users, .system/access-groups.json, etc.) so tests start from a
	// known state every time. The whole /tests/tcms-data/ tree is gitignored;
	// fixtures live at /tests/tcms-data-fixtures/ as the source of truth.
	// $forceComplete suppresses the restore — used when a test genuinely needs
	// an empty dir (e.g. setup-wizard tests).
	if ($isRootDataDir) {
		if (!$forceComplete) {
			restoreFixtures();
		}
		return true;
	}

	return rmdir($dir);
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

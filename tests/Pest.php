<?php

$_SERVER['APP_ENV'] = 'test';

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

expect()->extend('toBeOne', function () {
	return $this->toBe(1);
});

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
	$app = require __DIR__ . '/../config/bootstrap.php';

	return $app;
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

function templatePath(string $id): string
{
	return cmsDataDir() . "templates/$id.twig";
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

function recursiveDelete($dir)
{
	if (!file_exists($dir)) {
		return true;
	}

	if (!is_dir($dir)) {
		return unlink($dir);
	}

	foreach (scandir($dir) as $item) {
		if ($item == '.' || $item == '..') {
			continue;
		}

		if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item)) {
			return false;
		}
	}

	return rmdir($dir);
}

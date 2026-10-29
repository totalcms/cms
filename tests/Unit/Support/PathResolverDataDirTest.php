<?php

declare(strict_types=1);

use TotalCMS\Support\PathResolver;

/*
 * defaultDataDir() decides where tcms-data lives when config/tcms.php does not.
 * `composer create-project` scaffolds a starter before the setup wizard runs,
 * and a docroot default put that JSON in public/, where anyone could fetch it.
 */

beforeEach(function (): void {
	$this->site = sys_get_temp_dir() . '/tcms-datadir-' . bin2hex(random_bytes(4));
	mkdir($this->site . '/public', 0755, true);

	$this->setRoots = function (string $package, string $project): void {
		(new ReflectionProperty(PathResolver::class, 'packageRoot'))->setValue(null, $package);
		(new ReflectionProperty(PathResolver::class, 'projectRoot'))->setValue(null, $project);
	};
});

afterEach(function (): void {
	// Re-derive the real roots for every other test.
	(new ReflectionProperty(PathResolver::class, 'packageRoot'))->setValue(null, null);
	(new ReflectionProperty(PathResolver::class, 'projectRoot'))->setValue(null, null);
	exec('rm -rf ' . escapeshellarg($this->site));
});

describe('PathResolver::defaultDataDir', function (): void {
	test('a Composer install with no data folder yet uses the project root, outside the web root', function (): void {
		($this->setRoots)($this->site . '/vendor/totalcms/cms', $this->site);

		expect(PathResolver::defaultDataDir($this->site . '/public'))->toBe($this->site . '/tcms-data');
	});

	test('a zip install with no data folder yet uses the docroot', function (): void {
		($this->setRoots)($this->site . '/public/tcms', $this->site . '/public/tcms');

		expect(PathResolver::defaultDataDir($this->site . '/public'))->toBe($this->site . '/public/tcms-data');
	});

	test('an existing folder above the docroot wins', function (): void {
		($this->setRoots)($this->site . '/public/tcms', $this->site . '/public/tcms');
		mkdir($this->site . '/tcms-data');
		mkdir($this->site . '/public/tcms-data');

		expect(PathResolver::defaultDataDir($this->site . '/public'))->toBe($this->site . '/tcms-data');
	});

	test('an existing folder in the docroot is kept on a Composer install', function (): void {
		// Sites already set up that way keep working; nothing moves on its own.
		($this->setRoots)($this->site . '/vendor/totalcms/cms', $this->site);
		mkdir($this->site . '/public/tcms-data');

		expect(PathResolver::defaultDataDir($this->site . '/public'))->toBe($this->site . '/public/tcms-data');
	});
});

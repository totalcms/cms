<?php

declare(strict_types=1);

use TotalCMS\Support\PathResolver;

/*
 * tcmsBinary() must pick the executable from the package PATH STRUCTURE, not
 * from isComposerInstall() — that flag depends on TCMS_PROJECT_ROOT, which is
 * undefined during a plain web request, so the admin job-queue page used to
 * show the package's resources/bin/tcms instead of the project's vendor/bin/tcms
 * on Composer installs.
 */

/** Override the cached packageRoot so we can exercise both install layouts. */
function setPackageRoot(?string $path): void
{
	$prop = new ReflectionProperty(PathResolver::class, 'packageRoot');
	$prop->setValue(null, $path);
}

afterEach(function (): void {
	// Reset the cache so the real repo root is re-derived for other tests.
	setPackageRoot(null);
});

describe('PathResolver::tcmsBinary', function (): void {
	test('Composer layout resolves to the vendor/bin proxy', function (): void {
		setPackageRoot('/srv/mysite/vendor/totalcms/cms');

		expect(PathResolver::tcmsBinary())->toBe('/srv/mysite/vendor/bin/tcms');
	});

	test('zip layout resolves to the shipped resources/bin script', function (): void {
		setPackageRoot('/var/www/tcms');

		expect(PathResolver::tcmsBinary())->toBe('/var/www/tcms/resources/bin/tcms');
	});

	test('a non-vendor parent named totalcms is not mistaken for Composer', function (): void {
		// Needs BOTH parents (vendor/totalcms) — a stray "totalcms" dir alone
		// must still fall through to the zip branch.
		setPackageRoot('/home/totalcms/cms');

		expect(PathResolver::tcmsBinary())->toBe('/home/totalcms/cms/resources/bin/tcms');
	});
});

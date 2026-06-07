<?php

declare(strict_types=1);

use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/*
 * The log directory default is layout-aware AND datadir-relative, so it must
 * be resolved in Config AFTER the tcms.php merge — defaults.php loads before
 * a datadir override and would bake in the wrong path (the same load-order
 * trap as the OAuth key paths, see local.test.php). Zip installs log into
 * <datadir>/.system/logs so logs survive the app-directory swap during
 * updates; Composer installs keep <projectRoot>/logs, which composer update
 * never touches. An explicit logger.path always wins.
 */

function loggerPathSettings(): array
{
	return require dirname(__DIR__, 3) . '/config/settings.php';
}

describe('Config logger path resolution', function (): void {
	test('empty path resolves against the FINAL datadir (zip layout)', function (): void {
		// The dev/test repo has no TCMS_PROJECT_ROOT define — zip layout.
		expect(PathResolver::isComposerInstall())->toBeFalse();

		$settings                   = loggerPathSettings();
		$settings['datadir']        = '/custom/data-home';
		$settings['logger']['path'] = '';

		$config = new Config($settings);

		expect($config->logger['path'])->toBe('/custom/data-home/.system/logs');
	});

	test('an explicit logger.path override wins', function (): void {
		$settings                   = loggerPathSettings();
		$settings['logger']['path'] = '/var/log/tcms';

		$config = new Config($settings);

		expect($config->logger['path'])->toBe('/var/log/tcms');
	});

	test('the live test config honors the explicit local.test.php override', function (): void {
		// local.test.php pins logger.path to the repo-root logs/ so the test
		// suite doesn't write into (and churn) the test datadir.
		$config = Config::init();

		expect($config->logger['path'])->toBe(dirname(__DIR__, 3) . '/logs');
	});
});

<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Resolves paths for both zip installs and Composer installs.
 *
 * Package root: Where core CMS files live (src/, config/, resources/).
 *   - Zip install: the app directory (e.g., /var/www/tcms/)
 *   - Composer install: vendor/totalcms/cms/
 *
 * Project root: Where user-owned and writable files live (cache/, tmp/, logs/, tcms-data/).
 *   - Zip install: same as package root
 *   - Composer install: the project directory above public/ (e.g., /var/www/mysite/)
 *
 * For zip installs, TCMS_PROJECT_ROOT is never defined, so both roots are identical.
 * For Composer installs, the skeleton entry points define TCMS_PROJECT_ROOT.
 */
class PathResolver
{
	private static ?string $packageRoot = null;
	private static ?string $projectRoot = null;

	/**
	 * The root of the CMS package (where src/, config/, resources/ live).
	 */
	public static function packageRoot(): string
	{
		return self::$packageRoot ??= dirname(__DIR__, 2);
	}

	/**
	 * The root of the user's project (where cache/, tmp/, logs/ live).
	 * For zip installs, this is the same as packageRoot().
	 */
	public static function projectRoot(): string
	{
		return self::$projectRoot ??= defined('TCMS_PROJECT_ROOT')
			? (string)TCMS_PROJECT_ROOT
			: self::packageRoot();
	}

	/**
	 * Whether this is a Composer-based installation.
	 */
	public static function isComposerInstall(): bool
	{
		return self::packageRoot() !== self::projectRoot();
	}

	/**
	 * Absolute path to the `tcms` executable, for cron / shell display.
	 *
	 * Composer installs must use the generated bin proxy (`vendor/bin/tcms`):
	 * it sets `_composer_autoload_path` so the CLI finds the project's
	 * autoloader. The package's own `resources/bin/tcms` has no nested
	 * `vendor/` to autoload from, so a cron pointed at it would fail.
	 *
	 * We detect the Composer layout from the package path structure
	 * (`<project>/vendor/totalcms/cms`) rather than `isComposerInstall()`:
	 * that flag depends on `TCMS_PROJECT_ROOT`, which is only defined by the
	 * CLI / skeleton entry points — NOT during a plain web request — so it
	 * wrongly reports "zip" while rendering the admin job-queue page. The
	 * path structure is reliable in every context (mirrors CliApplication's
	 * own project-root detection).
	 */
	public static function tcmsBinary(): string
	{
		$vendorTotalcms = dirname(self::packageRoot()); // <project>/vendor/totalcms
		$vendorDir      = dirname($vendorTotalcms);      // <project>/vendor
		if (basename($vendorTotalcms) === 'totalcms' && basename($vendorDir) === 'vendor') {
			return $vendorDir . '/bin/tcms';
		}

		return self::packageRoot() . '/resources/bin/tcms';
	}

	/**
	 * Location of the writable installation config (`tcms.php`).
	 *
	 * Composer installs keep it at `<project-root>/config/tcms.php` so the
	 * vendor package can remain read-only. Zip / Stacks installs keep it at
	 * `DOCUMENT_ROOT/tcms.php` (the legacy location). The Settings UI and
	 * setup wizard write through this so reads in `config/settings.php` see
	 * the same file back.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public static function configFile(): string
	{
		return self::isComposerInstall()
			? self::projectRoot() . '/config/tcms.php'
			: ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/tcms.php';
	}
}

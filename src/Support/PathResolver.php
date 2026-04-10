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
			? (string) TCMS_PROJECT_ROOT
			: self::packageRoot();
	}

	/**
	 * Whether this is a Composer-based installation.
	 */
	public static function isComposerInstall(): bool
	{
		return self::packageRoot() !== self::projectRoot();
	}
}

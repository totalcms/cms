<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Support\Config;

/**
 * Gives the data directory the .htaccess that denies web access to it.
 *
 * The setup wizard wrote that file only when it created the folder itself. A
 * folder that already existed — created by the first request, or on a 3.6.4
 * Composer install by `composer create-project`, which put it in public/ — was
 * kept without one, so a docroot data folder served every object and .system/
 * (API keys among them) to anyone who asked. Found 2026-10-30.
 *
 * Runs on the first request after updating, and on a brand-new install's first
 * request, so the folder is protected before anything else is written to it.
 * Leaves an existing .htaccess alone. Nginx does not read .htaccess; a data
 * folder in the docroot there needs the deny rule in docs/operations/nginx.
 */
readonly class ProtectDataDirectoryMigration implements MigrationInterface
{
	public function __construct(
		private Config $config,
		private DataDirectoryManager $directoryManager,
	) {
	}

	public function id(): string
	{
		return 'protect-data-directory';
	}

	public function description(): string
	{
		return 'Add the .htaccess that denies web access to the data directory.';
	}

	public function run(): int
	{
		$datadir = rtrim($this->config->datadir, '/');
		if ($datadir === '' || !FileUtils::ensureDirectory($datadir)) {
			return 0;
		}

		return $this->directoryManager->ensureSecurityFile($datadir) ? 1 : 0;
	}
}

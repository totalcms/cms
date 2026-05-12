<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings\Services;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Repository\InstallationRepository;

/**
 * Saves installation settings to tcms.php.
 *
 * Installation settings are bootstrap configuration that must be
 * available before the data directory is loaded (e.g., datadir path).
 */
readonly class InstallationSettingsSaver
{
	public function __construct(
		private CacheManager $cacheManager,
		private InstallationRepository $installationRepository,
	) {
	}

	/**
	 * Save installation settings to tcms.php.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function saveSettings(array $settings): void
	{
		$this->installationRepository->save($settings);
		$this->cacheManager->clearAllCaches();
	}
}

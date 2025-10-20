<?php

namespace TotalCMS\Domain\Settings\Services;

use TotalCMS\Domain\Settings\Repository\InstallationRepository;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;

/**
 * Fetches settings from settings.json and tcms.php.
 *
 * Installation settings (datadir) are loaded from tcms.php via InstallationRepository.
 * All other settings are loaded from settings.json via SettingsRepository.
 */
readonly class SettingsFetcher
{
	public function __construct(
		private SettingsRepository $settingsRepository,
		private InstallationRepository $installationRepository,
	) {
	}

	/**
	 * Load all settings from settings.json.
	 *
	 * @return array<string,mixed>
	 */
	public function loadSettings(): array
	{
		return $this->settingsRepository->load();
	}

	/**
	 * Load installation settings from tcms.php.
	 *
	 * @return array<string,mixed>
	 */
	public function loadInstallationSettings(): array
	{
		return $this->installationRepository->load();
	}

	/**
	 * Load settings for a specific section.
	 *
	 * @return array<string,mixed>
	 */
	public function loadSection(string $section): array
	{
		// Installation settings come from tcms.php
		if ($section === 'installation') {
			return $this->loadInstallationSettings();
		}

		// All other settings come from settings.json
		$settings = $this->loadSettings();

		// General settings are stored at the top level, not under 'general' key
		if ($section === 'general') {
			// Extract only the general settings fields from top level (removed datadir, now in installation)
			$generalFields   = ['sentry', 'api', 'notfound', 'timezone', 'locale'];
			$generalSettings = [];
			foreach ($generalFields as $field) {
				if (isset($settings[$field])) {
					$generalSettings[$field] = $settings[$field];
				}
			}

			return $generalSettings;
		}

		return $settings[$section] ?? [];
	}
}

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
		private SettingsSchemaFetcher $schemaFetcher,
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

		// General settings are stored at the top level of settings.json, not
		// under a 'general' key. The set of "general" fields is whatever the
		// general settings schema declares — derive dynamically so adding a
		// new field to general.json doesn't require updating this list. Falls
		// back to a safe minimal set if the schema can't be loaded.
		if ($section === 'general') {
			$generalFields   = array_keys($this->schemaFetcher->getProperties('general'));
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

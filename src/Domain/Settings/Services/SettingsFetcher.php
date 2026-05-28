<?php

namespace TotalCMS\Domain\Settings\Services;

use TotalCMS\Domain\Settings\Repository\SettingsRepository;

/**
 * Fetches settings from settings.json.
 *
 * Bootstrap configuration in tcms.php (datadir) is managed by the
 * Setup Wizard via InstallationRepository / InstallationSettingsSaver —
 * not exposed through this service.
 */
readonly class SettingsFetcher
{
	public function __construct(
		private SettingsRepository $settingsRepository,
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
	 * Load settings for a specific section.
	 *
	 * @return array<string,mixed>
	 */
	public function loadSection(string $section): array
	{
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

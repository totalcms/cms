<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings\Services;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;

/**
 * Saves settings to settings.json in tcms-data/.system/.
 */
readonly class SettingsSaver
{
	public function __construct(
		private SettingsFetcher $settingsFetcher,
		private SettingsValidator $settingsValidator,
		private CacheManager $cacheManager,
		private SettingsRepository $settingsRepository,
	) {
	}

	/**
	 * Save settings for a specific section.
	 *
	 * @param array<string,mixed> $sectionData
	 */
	public function saveSection(string $section, array $sectionData): void
	{
		// Validate and process the section data
		$sectionData = $this->settingsValidator->processSection($section, $sectionData);

		$settings = $this->settingsFetcher->loadSettings();

		// General settings are saved at the top level, not under 'general' key
		if ($section === 'general') {
			$settings = self::deepMergeArrays($settings, $sectionData);
		} elseif ($section === 'presets') {
			// Presets use deck field - each submitted top-level key (e.g. presetsettings)
			// is the full intended state of that deck, so we replace those keys outright
			// (deep-merge can't represent deletions). Other keys under 'presets' that
			// weren't submitted are preserved.
			if (!isset($settings[$section]) || !is_array($settings[$section])) {
				$settings[$section] = [];
			}
			foreach ($sectionData as $key => $value) {
				$settings[$section][$key] = $value;
			}
		} elseif (isset($settings[$section]) && is_array($settings[$section])) {
			// Deep merge the section data
			$settings[$section] = self::deepMergeArrays($settings[$section], $sectionData);
		} else {
			$settings[$section] = $sectionData;
		}

		$this->writeSettings($settings);
		$this->cacheManager->clearAllCaches();
	}

	/**
	 * Save entire settings array.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function saveSettings(array $settings): void
	{
		$this->writeSettings($settings);
		$this->cacheManager->clearAllCaches();
	}

	/**
	 * Delete a specific section from settings.
	 */
	public function deleteSection(string $section): void
	{
		$settings = $this->settingsFetcher->loadSettings();
		unset($settings[$section]);
		$this->writeSettings($settings);
		$this->cacheManager->clearAllCaches();
	}

	/**
	 * Write settings to settings.json file in tcms-data/.system/.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function writeSettings(array $settings): void
	{
		$this->settingsRepository->save($settings);
	}

	/**
	 * Deep merge two arrays recursively.
	 *
	 * @param array<string,mixed> $array1
	 * @param array<string,mixed> $array2
	 *
	 * @return array<string,mixed>
	 */
	public static function deepMergeArrays(array $array1, array $array2): array
	{
		$merged = $array1;

		foreach ($array2 as $key => $value) {
			if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
				$merged[$key] = self::deepMergeArrays($merged[$key], $value);
			} else {
				$merged[$key] = $value;
			}
		}

		return $merged;
	}
}

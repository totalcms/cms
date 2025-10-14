<?php

namespace TotalCMS\Domain\Settings\Services;

use TotalCMS\Domain\Cache\CacheManager;

/**
 * Saves settings to tcms.php.
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
readonly class SettingsSaver
{
	public function __construct(
		private SettingsFetcher $settingsFetcher,
		private SettingsValidator $settingsValidator,
		private CacheManager $cacheManager,
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

		// Deep merge the section data
		if (isset($settings[$section]) && is_array($settings[$section])) {
			$settings[$section] = $this->deepMergeArrays($settings[$section], $sectionData);
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
	 * Write settings to tcms.php file.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function writeSettings(array $settings): void
	{
		$configFile    = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		$configContent = "<?php\n\nreturn json_decode(<<<JSON\n";
		$configContent .= json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$configContent .= "\nJSON, true);\n";

		file_put_contents($configFile, $configContent);
	}

	/**
	 * Deep merge two arrays recursively.
	 *
	 * @param array<string,mixed> $array1
	 * @param array<string,mixed> $array2
	 *
	 * @return array<string,mixed>
	 */
	private function deepMergeArrays(array $array1, array $array2): array
	{
		$merged = $array1;

		foreach ($array2 as $key => $value) {
			if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
				$merged[$key] = $this->deepMergeArrays($merged[$key], $value);
			} else {
				$merged[$key] = $value;
			}
		}

		return $merged;
	}
}

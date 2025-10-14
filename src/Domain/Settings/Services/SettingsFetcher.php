<?php

namespace TotalCMS\Domain\Settings\Services;

/**
 * Fetches settings from tcms.php.
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
readonly class SettingsFetcher
{
	/**
	 * Load all settings from tcms.php.
	 *
	 * @return array<string,mixed>
	 */
	public function loadSettings(): array
	{
		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		if (!file_exists($configFile)) {
			return [];
		}

		$settings = require $configFile;

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Load settings for a specific section.
	 *
	 * @return array<string,mixed>
	 */
	public function loadSection(string $section): array
	{
		$settings = $this->loadSettings();

		return $settings[$section] ?? [];
	}

	/**
	 * Check if tcms.php exists.
	 */
	public function configFileExists(): bool
	{
		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';

		return file_exists($configFile);
	}
}

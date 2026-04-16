<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Settings\Repository;

use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository for managing settings.json in tcms-data/.system/.
 */
class SettingsRepository extends StorageRepository
{
	private const SETTINGS_FILE = '.system/settings.json';

	/**
	 * Request-level cache for settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $requestCache = null;

	/**
	 * Load all settings from settings.json.
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		if ($this->requestCache !== null) {
			return $this->requestCache;
		}

		if (!$this->filesystem->fileExists(self::SETTINGS_FILE)) {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$content = $this->filesystem->read(self::SETTINGS_FILE);
		if ($content === '') {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$settings = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->requestCache = [];

			return $this->requestCache;
		}

		$this->requestCache = is_array($settings) ? $settings : [];

		return $this->requestCache;
	}

	/**
	 * Save settings to settings.json.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function save(array $settings): void
	{
		// Write settings as JSON (Flysystem automatically creates parent directories)
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode settings to JSON: ' . json_last_error_msg());
		}

		$this->filesystem->write(self::SETTINGS_FILE, $json);

		// Invalidate cache after write
		$this->requestCache = null;
	}

	/**
	 * Check if settings.json exists.
	 */
	public function exists(): bool
	{
		return $this->filesystem->fileExists(self::SETTINGS_FILE);
	}
}

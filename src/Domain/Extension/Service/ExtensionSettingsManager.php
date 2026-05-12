<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Manages per-extension settings stored in tcms-data/.system/extension-settings/.
 */
final class ExtensionSettingsManager
{
	private const SETTINGS_DIR = '.system/extension-settings';

	/** @var array<string,array<string,mixed>> */
	private array $cache = [];

	public function __construct(
		private readonly StorageFilesystemAdapter $storage,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getSettings(string $extensionId): array
	{
		if (isset($this->cache[$extensionId])) {
			return $this->cache[$extensionId];
		}

		$path     = $this->settingsPath($extensionId);
		$settings = [];

		if ($this->storage->fileExists($path)) {
			$json = $this->storage->read($path);
			$data = json_decode($json, true);
			if (is_array($data)) {
				$settings = $data;
			}
		}

		$this->cache[$extensionId] = $settings;

		return $settings;
	}

	public function getSetting(string $extensionId, string $key, mixed $default = null): mixed
	{
		$settings = $this->getSettings($extensionId);

		return $settings[$key] ?? $default;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public function saveSettings(string $extensionId, array $settings): void
	{
		$this->cache[$extensionId] = $settings;
		$path                      = $this->settingsPath($extensionId);
		$json                      = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json !== false) {
			$this->storage->write($path, $json);
		}
	}

	public function deleteSettings(string $extensionId): void
	{
		unset($this->cache[$extensionId]);
		$path = $this->settingsPath($extensionId);

		if ($this->storage->fileExists($path)) {
			$this->storage->delete($path);
		}
	}

	private function settingsPath(string $extensionId): string
	{
		// vendor/extension-name → vendor/extension-name.json
		$safeName = str_replace('/', '/', $extensionId);

		return self::SETTINGS_DIR . '/' . $safeName . '.json';
	}
}

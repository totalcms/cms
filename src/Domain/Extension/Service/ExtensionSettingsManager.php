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

	/**
	 * @param string $datadir Absolute datadir path, used to enforce 0600 on
	 *                        written settings files. Always supplied in
	 *                        production via the DI container; optional only so
	 *                        test scaffolding can construct the manager with a
	 *                        bare storage adapter.
	 */
	public function __construct(
		private readonly StorageFilesystemAdapter $storage,
		private readonly string $datadir = '',
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

			// Settings can hold admin-entered secrets (API keys, tokens). The
			// shared storage adapter writes at the umask default (0644 —
			// group/world-readable on Linux), so enforce secret-grade 0600,
			// matching ExtensionStorage and the OAuth key convention.
			if ($this->datadir !== '') {
				chmod($this->datadir . '/' . $path, 0600);
			}
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

<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Config;

/**
 * Reads sync configuration from settings.json.
 *
 * Settings stored as:
 *   "sync": { "url": "https://...", "key": "..." }
 *
 * Currently hardcoded to a single "production" environment.
 * The structure allows future extension to multiple environments.
 */
class SyncConfig
{
	private string $url = '';
	private string $key = '';

	public function __construct(string $dataDir)
	{
		$settingsFile = $dataDir . '/.system/settings.json';
		$this->load($settingsFile);
	}

	/**
	 * @return array{url: string, key: string}|null
	 */
	public function getRemote(): ?array
	{
		if ($this->url === '' || $this->key === '') {
			return null;
		}

		return [
			'url' => $this->url,
			'key' => $this->key,
		];
	}

	public function isConfigured(): bool
	{
		return $this->url !== '' && $this->key !== '';
	}

	private function load(string $settingsFile): void
	{
		if (!file_exists($settingsFile)) {
			return;
		}

		$content = file_get_contents($settingsFile);
		if ($content === false) {
			return;
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return;
		}

		$sync = $data['sync'] ?? [];
		if (!is_array($sync)) {
			return;
		}

		$this->url = rtrim((string) ($sync['url'] ?? ''), '/');
		$this->key = (string) ($sync['key'] ?? '');
	}
}

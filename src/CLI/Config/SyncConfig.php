<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Config;

/**
 * Resolves the sync remote from the `sync` config bucket.
 *
 * Settings stored as:
 *   "sync": { "url": "https://...", "key": "..." }
 *
 * The bucket comes from Config, so it honours the full merge —
 * defaults.php, then config/tcms.php, then settings.json. This class used to
 * read tcms-data/.system/settings.json directly, which meant `sync` could
 * only ever be set there: installs sharing one data folder shared one remote
 * and one deploy key, with no way to override either per site.
 *
 * Currently hardcoded to a single "production" environment.
 * The structure allows future extension to multiple environments.
 */
class SyncConfig
{
	private string $url = '';
	private string $key = '';

	/**
	 * @param array<string,mixed> $sync The resolved `sync` config bucket.
	 */
	public function __construct(array $sync)
	{
		// Neither value is guaranteed to be a string: the bucket can come from
		// operator-edited JSON. Anything that isn't a string casts to one and
		// then fails the non-empty check below, the same as a missing key.
		$url = $sync['url'] ?? '';
		$key = $sync['key'] ?? '';

		$this->url = is_string($url) ? rtrim($url, '/') : '';
		$this->key = is_string($key) ? $key : '';
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
}

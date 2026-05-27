<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

use TotalCMS\Domain\OAuth\Data\OAuthClientData;

/**
 * Flat-file OAuth client store at tcms-data/.system/oauth-clients.json.
 *
 * Atomic writes: serialize to a temp file in the same directory, then
 * rename — POSIX guarantees the rename is atomic so concurrent readers
 * never see a partial file.
 */
final class OAuthClientRepository
{
	public function __construct(
		private readonly string $storagePath,
	) {
	}

	public function find(string $id): ?OAuthClientData
	{
		foreach ($this->loadAll() as $entry) {
			if (($entry['id'] ?? null) === $id) {
				return OAuthClientData::fromArray($entry);
			}
		}
		return null;
	}

	/**
	 * @return list<OAuthClientData>
	 */
	public function all(): array
	{
		return array_map(
			static fn (array $entry): OAuthClientData => OAuthClientData::fromArray($entry),
			$this->loadAll(),
		);
	}

	public function save(OAuthClientData $client): void
	{
		$entries = $this->loadAll();
		$found   = false;
		foreach ($entries as $i => $entry) {
			if (($entry['id'] ?? null) === $client->id) {
				$entries[$i] = $client->toArray();
				$found       = true;
				break;
			}
		}
		if (!$found) {
			$entries[] = $client->toArray();
		}
		$this->writeAtomic(['clients' => $entries]);
	}

	public function delete(string $id): void
	{
		$entries = array_values(array_filter(
			$this->loadAll(),
			static fn (array $entry): bool => ($entry['id'] ?? null) !== $id,
		));
		$this->writeAtomic(['clients' => $entries]);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function loadAll(): array
	{
		if (!is_file($this->storagePath)) {
			return [];
		}
		$raw  = (string)file_get_contents($this->storagePath);
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['clients']) || !is_array($data['clients'])) {
			return [];
		}
		return array_values(array_filter($data['clients'], 'is_array'));
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function writeAtomic(array $payload): void
	{
		$dir = dirname($this->storagePath);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$tmp     = $this->storagePath . '.tmp-' . bin2hex(random_bytes(4));
		$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		file_put_contents($tmp, (string)$encoded);
		rename($tmp, $this->storagePath);
	}
}

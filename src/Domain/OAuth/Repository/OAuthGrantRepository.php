<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

use TotalCMS\Domain\OAuth\Data\OAuthGrantData;

/**
 * Flat-file OAuth grant store at tcms-data/.system/oauth-grants.json.
 *
 * Atomic writes: serialize to a temp file in the same directory, then
 * rename — POSIX guarantees the rename is atomic so concurrent readers
 * never see a partial file.
 */
final class OAuthGrantRepository
{
	public function __construct(
		private readonly string $storagePath,
	) {
	}

	public function find(string $id): ?OAuthGrantData
	{
		foreach ($this->loadAll() as $entry) {
			if (($entry['id'] ?? null) === $id) {
				return OAuthGrantData::fromArray($entry);
			}
		}

		return null;
	}

	public function findByRefreshTokenHash(string $hash): ?OAuthGrantData
	{
		foreach ($this->loadAll() as $entry) {
			if (($entry['refresh_token_hash'] ?? null) === $hash) {
				return OAuthGrantData::fromArray($entry);
			}
		}

		return null;
	}

	/**
	 * @return list<OAuthGrantData>
	 */
	public function findByClientId(string $clientId): array
	{
		$results = [];
		foreach ($this->loadAll() as $entry) {
			if (($entry['client_id'] ?? null) === $clientId) {
				$results[] = OAuthGrantData::fromArray($entry);
			}
		}

		return $results;
	}

	/**
	 * @return list<OAuthGrantData>
	 */
	public function all(): array
	{
		return array_map(
			static fn (array $entry): OAuthGrantData => OAuthGrantData::fromArray($entry),
			$this->loadAll(),
		);
	}

	public function save(OAuthGrantData $grant): void
	{
		$entries = $this->loadAll();
		$found   = false;
		foreach ($entries as $i => $entry) {
			if (($entry['id'] ?? null) === $grant->id) {
				$entries[$i] = $grant->toArray();
				$found       = true;
				break;
			}
		}
		if (!$found) {
			$entries[] = $grant->toArray();
		}
		$this->writeAtomic(['grants' => $entries]);
	}

	public function delete(string $id): void
	{
		$entries = array_values(array_filter(
			$this->loadAll(),
			static fn (array $entry): bool => ($entry['id'] ?? null) !== $id,
		));
		$this->writeAtomic(['grants' => $entries]);
	}

	public function deleteByClientId(string $clientId): void
	{
		$entries = array_values(array_filter(
			$this->loadAll(),
			static fn (array $entry): bool => ($entry['client_id'] ?? null) !== $clientId,
		));
		$this->writeAtomic(['grants' => $entries]);
	}

	/**
	 * Remove grants whose expires_at is in the past (UTC ISO 8601).
	 *
	 * @return int Number of grants removed
	 */
	public function pruneExpired(): int
	{
		$now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$before  = $this->loadAll();
		$entries = array_values(array_filter(
			$before,
			static function (array $entry) use ($now): bool {
				$expiresAt = $entry['expires_at'] ?? null;
				if (!is_string($expiresAt) || $expiresAt === '') {
					return false;
				}
				try {
					$expiry = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));

					return $expiry > $now;
				} catch (\Exception) {
					return false;
				}
			},
		));
		$removed = count($before) - count($entries);
		if ($removed > 0) {
			$this->writeAtomic(['grants' => $entries]);
		}

		return $removed;
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
		if (!is_array($data) || !isset($data['grants']) || !is_array($data['grants'])) {
			return [];
		}

		return array_values(array_filter($data['grants'], 'is_array'));
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

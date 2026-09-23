<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\Storage\JsonListRepository;

/**
 * OAuth grants (refresh-token chains), in `.system/oauth-grants.json`. The
 * file holds refresh-token hashes, so it is written private and under a lock.
 */
final class OAuthGrantRepository extends JsonListRepository
{
	protected function rootKey(): string
	{
		return 'grants';
	}

	public function find(string $id): ?OAuthGrantData
	{
		$entry = $this->findEntry('id', $id);

		return $entry === null ? null : OAuthGrantData::fromArray($entry);
	}

	public function findByRefreshTokenHash(string $hash): ?OAuthGrantData
	{
		$entry = $this->findEntry('refresh_token_hash', $hash);

		return $entry === null ? null : OAuthGrantData::fromArray($entry);
	}

	/** @return list<OAuthGrantData> */
	public function findByClientId(string $clientId): array
	{
		$results = [];
		foreach ($this->entries() as $entry) {
			if (($entry['client_id'] ?? null) === $clientId) {
				$results[] = OAuthGrantData::fromArray($entry);
			}
		}

		return $results;
	}

	/** @return list<OAuthGrantData> */
	public function all(): array
	{
		return array_map(OAuthGrantData::fromArray(...), $this->entries());
	}

	public function save(OAuthGrantData $grant): void
	{
		$this->upsert($grant->toArray());
	}

	public function delete(string $id): void
	{
		$this->removeWhere(static fn (array $entry): bool => ($entry['id'] ?? null) !== $id);
	}

	public function deleteByClientId(string $clientId): void
	{
		$this->removeWhere(static fn (array $entry): bool => ($entry['client_id'] ?? null) !== $clientId);
	}

	/**
	 * Drop every grant whose `expires_at` has passed (or is missing or
	 * unreadable). The file is written only when something was dropped.
	 *
	 * @return int grants removed
	 */
	public function pruneExpired(): int
	{
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		return $this->removeWhere(static function (array $entry) use ($now): bool {
			$expiresAt = $entry['expires_at'] ?? null;
			if (!is_string($expiresAt) || $expiresAt === '') {
				return false;
			}
			try {
				return new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')) > $now;
			} catch (\Exception) {
				return false;
			}
		});
	}
}

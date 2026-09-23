<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\Storage\JsonListRepository;

/**
 * Registered OAuth clients, in `.system/oauth-clients.json`.
 */
final class OAuthClientRepository extends JsonListRepository
{
	protected function rootKey(): string
	{
		return 'clients';
	}

	public function find(string $id): ?OAuthClientData
	{
		$entry = $this->findEntry('id', $id);

		return $entry === null ? null : OAuthClientData::fromArray($entry);
	}

	/** @return list<OAuthClientData> */
	public function all(): array
	{
		return array_map(OAuthClientData::fromArray(...), $this->entries());
	}

	public function save(OAuthClientData $client): void
	{
		$this->upsert($client->toArray());
	}

	public function delete(string $id): void
	{
		$this->removeWhere(static fn (array $entry): bool => ($entry['id'] ?? null) !== $id);
	}
}

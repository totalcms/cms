<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;

/**
 * Bridges league's ClientRepositoryInterface to T3's OAuthClientRepository.
 *
 * Secret validation uses password_verify() against the bcrypt hash stored in
 * OAuthClientData. Public clients (isConfidential = false) may omit the
 * secret — passing null is accepted as valid for them.
 */
final class LeagueClientRepository implements ClientRepositoryInterface
{
	public function __construct(
		private readonly OAuthClientRepository $clients,
	) {
	}

	public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
	{
		$client = $this->clients->find($clientIdentifier);
		if ($client === null) {
			return null;
		}
		return new LeagueClientEntity($client);
	}

	public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
	{
		$client = $this->clients->find($clientIdentifier);
		if ($client === null) {
			return false;
		}

		// Public clients may omit the secret.
		if ($clientSecret === null) {
			return !$client->isConfidential;
		}

		return password_verify($clientSecret, $client->secretHash);
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * Stub implementation of league's UserRepositoryInterface.
 *
 * The password grant is not enabled in T3's OAuth layer. The auth-code
 * grant resolves the user via the active admin session rather than
 * consulting this repository. Returning null causes league to reject
 * any password-grant request with an "invalid_credentials" error.
 */
final class LeagueUserRepository implements UserRepositoryInterface
{
	public function getUserEntityByUserCredentials(
		string $username,
		string $password,
		string $grantType,
		ClientEntityInterface $clientEntity,
	): ?UserEntityInterface {
		// Password grant is intentionally disabled. Return null so league
		// rejects the request with invalid_credentials.
		return null;
	}
}

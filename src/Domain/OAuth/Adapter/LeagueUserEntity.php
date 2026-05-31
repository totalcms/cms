<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * Minimal user entity returned when a user identifier is known.
 * Used internally; the password grant is disabled in T3, so
 * LeagueUserRepository always returns null.
 */
final readonly class LeagueUserEntity implements UserEntityInterface
{
	public function __construct(
		private string $identifier,
	) {
	}

	/**
	 * @return non-empty-string
	 */
	public function getIdentifier(): string
	{
		return $this->identifier; // @phpstan-ignore-line (caller guarantees non-empty)
	}
}

<?php

declare(strict_types=1);

namespace Tests\Unit\XmlRpc\Stubs;

use TotalCMS\Domain\Auth\Service\UserValidationService;

/**
 * Returns the injected user, or throws when constructed with null — the two
 * outcomes XmlRpcAuth distinguishes. Readonly (parent is a readonly class),
 * named + namespaced (anonymous readonly classes are PHP 8.3+; CI runs the
 * 8.2 floor). See XmlRpcStubObjectUrlBuilder for the full rationale.
 */
readonly class XmlRpcAuthStubUserValidationService extends UserValidationService
{
	/** @param array<string,mixed>|null $user */
	public function __construct(private ?array $user)
	{
	}

	public function validateUser(string $idOrEmail, string $collection = ''): array
	{
		if ($this->user === null) {
			throw new \Exception('User not found');
		}

		return $this->user;
	}
}

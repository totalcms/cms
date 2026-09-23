<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Session;

use Odan\Session\SessionInterface;

/**
 * The user a session is logged in as: the AUTH_USER / AUTH_COLLECTION pair
 * that {@see \TotalCMS\Domain\Auth\Service\SessionLogin} writes. A session
 * without a user id is anonymous. The collection may be empty — the
 * validators treat that as the configured default auth collection.
 */
final readonly class SessionUser
{
	public function __construct(
		public string $id,
		public string $collection,
	) {
	}

	public static function fromSession(SessionInterface $session): ?self
	{
		$id         = (string)($session->get(SessionKeys::AUTH_USER) ?? '');
		$collection = (string)($session->get(SessionKeys::AUTH_COLLECTION) ?? '');

		if ($id === '') {
			return null;
		}

		return new self($id, $collection);
	}
}

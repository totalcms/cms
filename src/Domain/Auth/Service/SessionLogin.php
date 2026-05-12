<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Establishes a logged-in session from an authenticated user. Centralises the
 * session-key writes so every entry point that "logs someone in" — the regular
 * login form, the setup wizard, public registration, custom flows in
 * extensions — sets the same keys in the same order, with the same license
 * deferral pattern.
 *
 * Pre-condition: the caller has already authenticated the user (verified
 * password, confirmed they exist in the collection, etc). This service does
 * NOT authenticate — it only writes session state. Misusing it bypasses
 * authentication. Pair it with {@see LoginService::authenticate()} or
 * equivalent verification before calling.
 */
readonly class SessionLogin
{
	public function __construct(
		private SessionInterface $session,
	) {
	}

	/**
	 * Write the four session keys that mark a user as logged in. License
	 * validation is intentionally deferred to the next request via the
	 * LICENSE_CHECK_DUE flag — keeps the login path off the network and lets
	 * LicenseValidationMiddleware redirect to the license manager on failure.
	 */
	public function establish(string $userId, string $collection, bool $persistent = false): void
	{
		$this->session->set(SessionKeys::AUTH_USER, $userId);
		$this->session->set(SessionKeys::AUTH_COLLECTION, $collection);
		$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, $persistent);
		$this->session->set(SessionKeys::LICENSE_CHECK_DUE, true);
	}
}

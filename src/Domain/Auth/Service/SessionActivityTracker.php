<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * The session lifecycle policy, applied once per authenticated request:
 * record the activity timestamp, rotate the session id every quarter of the
 * configured lifetime, and expire a session that has been idle longer than
 * the lifetime — unless a remember-me token backs it, in which case the
 * session stays and expired tokens are pruned instead.
 *
 * AuthMiddleware and DualAuthMiddleware each carried a private copy of this.
 */
final readonly class SessionActivityTracker
{
	public function __construct(
		private SessionInterface $session,
		private Config $config,
		private PersistentLoginService $persistentLoginService,
	) {
	}

	public function touch(): void
	{
		$now  = time();
		$last = (int)($this->session->get(SessionKeys::LAST_ACTIVITY) ?? $now);
		$max  = (int)$this->config->session['gc_maxlifetime'];

		// hasPersistentLoginOrCookie() also covers the case where the session
		// was garbage collected but the cookie still exists.
		$isPersistentLogin = $this->persistentLoginService->hasPersistentLoginOrCookie();

		$this->session->set(SessionKeys::LAST_ACTIVITY, $now);

		if ($now - $last > $max / 4 && $this->session instanceof SessionManagerInterface) {
			$this->session->regenerateId();
		}

		if ($now - $last <= $max) {
			return;
		}

		if ($isPersistentLogin) {
			$this->persistentLoginService->cleanupExpiredTokens();

			return;
		}

		// Idle past the lifetime with nothing backing it: clear and destroy.
		$this->session->clear();
		if ($this->session instanceof SessionManagerInterface) {
			$this->session->destroy();
		}
	}
}

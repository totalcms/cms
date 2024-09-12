<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;

final class LogoutService
{
	public function __construct(
		private PhpSession $session
	) {}

	public function logout(): bool
	{
		$this->session->clear();
		$this->session->destroy();
		return self::destroySession();
	}

	public static function destroySession(): bool
	{
		// this is purely a failsafe, as session->destroy() should already have done this
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_unset();
			session_destroy();
		}
		return session_status() !== PHP_SESSION_ACTIVE;
	}
}

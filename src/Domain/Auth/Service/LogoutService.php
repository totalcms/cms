<?php

namespace TotalCMS\Domain\Auth\Service;

final class LogoutService
{
	public function __construct() {}

	public function logout(): bool
	{
		return self::destroySession();
	}

	public static function destroySession(): bool
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_unset();
			session_destroy();
		}
		return session_status() !== PHP_SESSION_ACTIVE;
	}
}

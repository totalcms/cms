<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;

readonly class LogoutService
{
	public LoggerInterface $logger;

	public function __construct(
		private PhpSession $session,
		private LoggerFactory $loggerFactory,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(LoginService::ACCESS_LOG)->createLogger('logout');
	}

	public function logout(): bool
	{
		$user            = $this->session->get(SessionKeys::AUTH_USER) ?? 'unknown';
		$persistentLogin = $this->session->get(SessionKeys::AUTH_PERSISTENT_LOGIN, false);

		$this->logger->info("User $user logged out");

		// If this was a persistent login session, clear the persistent cookie
		if ($persistentLogin) {
			$this->clearPersistentCookie();
		}

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

	/**
	 * Clear persistent session cookie by setting it to expire in the past.
	 */
	private function clearPersistentCookie(): void
	{
		$sessionName  = $this->session->getName();
		$cookieParams = session_get_cookie_params();

		// Set cookie to expire in the past to delete it
		setcookie(
			$sessionName,
			'',
			[
				'expires'  => time() - 3600, // 1 hour ago
				'path'     => $cookieParams['path'],
				'domain'   => $cookieParams['domain'],
				'secure'   => $cookieParams['secure'],
				'httponly' => $cookieParams['httponly'],
				'samesite' => $cookieParams['samesite'],
			]
		);
	}
}

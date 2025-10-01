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
		private PersistentLoginService $persistentLoginService,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(LoginService::ACCESS_LOG)->createLogger('logout');
	}

	public function logout(): bool
	{
		$user            = $this->session->get(SessionKeys::AUTH_USER) ?? 'unknown';
		$persistentLogin = $this->session->get(SessionKeys::AUTH_PERSISTENT_LOGIN, false);

		$this->logger->info("User $user logged out");

		// If this was a persistent login session, clear the persistent login
		if ($persistentLogin) {
			$this->persistentLoginService->clearPersistentLogin();
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
}

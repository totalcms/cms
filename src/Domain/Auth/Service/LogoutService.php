<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;

final class LogoutService
{
	private LoggerInterface $logger;

	public function __construct(
		private PhpSession $session,
		private LoggerFactory $loggerFactory,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(LoginService::ACCESS_LOG)->createLogger();
	}

	public function logout(): bool
	{
		$user = $this->session->get('user') ?? "unknown";
		$this->logger->info("User $user logged out");

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

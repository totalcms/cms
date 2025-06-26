<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class AccessManager
{
	private LoggerInterface $logger;

	private string $defaultAuthCollection;
	private string $userID;
	private string $userCollection;

	public function __construct(
		private PhpSession $session,
		private Config $config,
		private UserValidationService $userValidator,
		private LoggerFactory $loggerFactory,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(LoginService::ACCESS_LOG)->createLogger('access');

		$this->defaultAuthCollection = $this->config->auth['collection'];
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param string|array<string> $groups
	 */
	public function restrictPageAccess(array|string $groups = [], string $collection = ''): void
	{
		$this->session->set('requestRefererUrl', $_SERVER['HTTP_REFERER'] ?? '');
		$this->session->set('requestOriginUrl', $_SERVER['REQUEST_URI']);

		if (!$this->sessionHasUser()) {
			$this->redirectToLogin($collection);

			return;
		}

		if (!$this->userHasAccess($groups, $collection)) {
			$this->redirectToAccessDenied();
		}
	}

	/** @param string|array<string> $groups */
	public function userHasAccess(array|string $groups, string $collection = ''): bool
	{
		if (!$this->userLoggedIn($collection)) {
			return false;
		}

		if (empty($groups)) {
			return $this->userLoggedIn($collection);
		}

		if (is_string($groups)) {
			$groups = [$groups];
		}

		try {
			if ($this->userValidator->validateUserInGroups($this->userID, $groups, $collection)) {
				return true;
			}
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		return false;
	}

	public function userLoggedIn(string $collection = ''): bool
	{
		if (!$this->sessionHasUser()) {
			return false;
		}

		// Get the user data from the session. It was not available in the constructor
		$this->getSessionData();

		if ($this->isSuperAdmin()) {
			return true;
		}

		if (empty($collection)) {
			$collection = $this->defaultAuthCollection;
		}

		if ($this->userCollection !== $collection) {
			return false;
		}

		try {
			if ($this->userValidator->validateUserById($this->userID, $collection)) {
				return true;
			}
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		return false;
	}

	/** @return array<string,mixed> */
	public function userData(): array
	{
		if (!$this->sessionHasUser()) {
			return [];
		}

		$this->getSessionData();

		try {
			$userData = $this->userValidator->validateUserById($this->userID, $this->userCollection);
		} catch (\Throwable $th) {
			// Current session user could be in a different user collection
			// $this->session->delete('user');
			return [];
		}

		$userData['collection'] = $this->userCollection;

		return $userData;
	}

	private function isSuperAdmin(): bool
	{
		$this->getSessionData();

		return $this->userCollection === $this->defaultAuthCollection
			&& $this->userValidator->isSuperAdmin($this->userID);
	}

	private function getSessionData(): void
	{
		if (!$this->sessionHasUser()) {
			return;
		}

		$this->userID         = $this->session->get('user') ?? '';
		$this->userCollection = $this->session->get('collection') ?? '';

		if (empty($this->userCollection)) {
			$this->userCollection = $this->defaultAuthCollection;
		}
	}

	public function sessionHasUser(): bool
	{
		return $this->session->has('user') && $this->session->has('collection');
	}

	private function redirectToLogin(string $collection = ''): void
	{
		$loginUrl = $this->config->api . '/login';
		if (!empty($collection)) {
			$loginUrl .= "/$collection";
		}
		header("Location: $loginUrl");
	}

	private function redirectToAccessDenied(): void
	{
		$deniedUrl = $this->config->api . '/denied';
		header("Location: $deniedUrl");
	}
}

<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

final class LoginService
{
	public function __construct(
		private PhpSession $session,
		private IndexSearcher $searcher,
		private ObjectFetcher $objectFetcher,
		private LoginUpdateService $updateService,
		private Config $config,
	) {}

	public function authenticate(string $email, string $password, string $collection = ''): bool
	{
		if (empty($collection)) {
			$collection = $this->config->auth['collection'];
		}

		$users = $this->searcher->searchByProperty($collection, 'email', $email);

		if (empty($users)) {
			throw new \Exception('User not found');
		}
		$user = ($this->objectFetcher->fetchObject($collection, $users[0]['id']))->toArray();

		if (!isset($user['active']) || !$user['active']) {
			throw new \Exception('User account is not active');
		}

		if (isset($user['expiration']) && strtotime($user['expiration']) > time()) {
			throw new \Exception('User account has expired');
		}

		if (!password_verify($password, $user['password'])) {
			throw new \Exception('Invalid password');
		}

		// Update the last login date of the user
		$this->updateService->updateLoginDate($collection, $user['id']);

		// Clear all flash messages
		$flash = $this->session->getFlash();
		$flash->clear();

		$this->session->destroy();
		$this->session->start();
		$this->session->regenerateId();

		$this->session->set('user', $user['id']);
		$flash->add('success', 'Login successful');

		return true;
	}
}

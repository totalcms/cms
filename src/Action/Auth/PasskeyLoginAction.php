<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\LastLoginUpdateService;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;

readonly class PasskeyLoginAction
{
	public function __construct(
		private PasskeyService $passkeyService,
		private PhpSession $session,
		private LastLoginUpdateService $lastLoginUpdateService,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$body = (string)$request->getBody();

		try {
			$result     = $this->passkeyService->verifyAuthentication($body);
			$user       = $result['user'];
			$collection = $result['collection'];

			// Run user state checks (same as LoginService)
			$this->checkUserState($user);

			// Update last login
			$this->lastLoginUpdateService->updateLoginDate($collection, (string)$user['id']);

			// Set up session (mirrors AuthLoginSubmitAction)
			$this->session->destroy();
			$this->session->start();
			$this->session->regenerateId();
			$this->session->set(SessionKeys::AUTH_USER, $user['id']);
			$this->session->set(SessionKeys::AUTH_COLLECTION, $collection);
			$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, false);
			$this->session->delete(SessionKeys::LOGIN_ATTEMPTS);

			return $this->jsonRenderer->json($response, [
				'success'  => true,
				'redirect' => '/admin',
			]);
		} catch (\Throwable $e) {
			return $this->jsonRenderer->json($response->withStatus(401), [
				'success' => false,
				'error'   => $e->getMessage(),
			]);
		}
	}

	/**
	 * Check user state (active, expiration, max login count).
	 *
	 * @param array<string,mixed> $user
	 */
	private function checkUserState(array $user): void
	{
		if (!isset($user['active']) || !$user['active']) {
			throw new \RuntimeException('User account is not active');
		}

		if (
			isset($user['expiration'])
			&& !empty($user['expiration'])
			&& strtotime((string)$user['expiration']) < time()
		) {
			throw new \RuntimeException('User account has expired');
		}

		if (
			isset($user['maxLoginCount'], $user['loginCount'])
			&& $user['maxLoginCount'] > 0
			&& $user['loginCount'] >= $user['maxLoginCount']
		) {
			throw new \RuntimeException('User account has reached the maximum login count');
		}
	}
}

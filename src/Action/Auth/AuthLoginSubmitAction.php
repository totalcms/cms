<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;
use TotalCMS\Domain\Auth\Service\LoginService;
use Slim\Routing\RouteContext;
use Odan\Session\PhpSession;

/**
 * Action.
 */
final class AuthLoginSubmitAction
{
	const MAX_LOGIN_ATTEMPTS = 7;

	public function __construct(
		private PhpSession $session,
		private LoginService $loginService,
	) {}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		// Clear all flash messages
		$flash = $this->session->getFlash();
		$flash->clear();

		$attempts = $this->session->get('loginAttempts', 0);
		$this->session->set('loginAttempts', $attempts + 1);

		if ($attempts > self::MAX_LOGIN_ATTEMPTS) {
			$flash->add('error', 'Too many login attempts');
		}

		if (!isset($data['email']) || !isset($data['password'])) {
			// throw new HttpUnauthorizedException($request, 'Email and password are required');
			$flash->add('error', 'Email and password are required');
		}

		$router = RouteContext::fromRequest($request)->getRouteParser();
		$url    = $router->urlFor('login');

		if (isset($args['collection'])) {
			$url = $router->urlFor('login', ['collection' => $args['collection']]);
		}

		if ($flash->has('error')) {
			return $response->withStatus(302)->withHeader('Location', $url);
		}

		$email      = $data['email'];
		$password   = $data['password'];
		$collection = $args['collection'] ?? '';

		try {
			$user = $this->loginService->authenticate($email, $password, $collection);
		} catch (\Exception $e) {
			// throw new HttpUnauthorizedException($request, $e->getMessage());
			$flash->add('error', $e->getMessage());
		}

		if (isset($user) && isset($user['id'])) {
			$url = $this->session->get('requestOriginUrl', $router->urlFor('admin-index'));

			$this->session->destroy();
			$this->session->start();
			$this->session->regenerateId();

			$this->session->set('user', $user['id']);
			$this->session->delete('loginAttempts');

			$flash->add('success', 'Login successful');
		}

		return $response->withStatus(302)->withHeader('Location', $url);
	}
}

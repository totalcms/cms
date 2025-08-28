<?php

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Action.
 */
readonly class AuthLoginSubmitAction
{
	public const MAX_LOGIN_ATTEMPTS = 10;

	public function __construct(
		private PhpSession $session,
		private LoginService $loginService,
		private Config $config,
	) {
	}

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

		$attempts = $this->session->get(SessionKeys::LOGIN_ATTEMPTS, 0);
		$this->session->set(SessionKeys::LOGIN_ATTEMPTS, $attempts + 1);

		$maxAttempts = $this->config->auth['maxAttempts'] ?? self::MAX_LOGIN_ATTEMPTS;

		if ($attempts > $maxAttempts) {
			$flash->add('error', 'Too many login attempts');
		}

		if (!isset($data['email'], $data['password'])) {
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

		$email           = $data['email'];
		$password        = $data['password'];
		$persistentLogin = isset($data['persistent_login']) && $data['persistent_login'] === '1';
		$collection      = $args['collection'] ?? '';

		try {
			$user = $this->loginService->authenticate($email, $password, $collection);
		} catch (\Exception $e) {
			// throw new HttpUnauthorizedException($request, $e->getMessage());
			$flash->add('error', $e->getMessage());
		}

		if (isset($user, $user['id'])) {
			// Check for redirect URL in multiple places:
			// 1. POST data (from login form with redirect parameter)
			// 2. Query parameter (for direct links)
			// 3. Session storage (for direct login)
			// 4. Default to admin index
			$postData    = (array)$request->getParsedBody();
			$queryParams = $request->getQueryParams();
			$redirectUrl = $postData['redirect'] ?? $queryParams['redirect'] ?? $this->session->get(SessionKeys::REQUEST_ORIGIN_URL, $router->urlFor('admin-index'));
			$url         = $redirectUrl;

			$this->session->destroy();
			$this->session->start();
			$this->session->regenerateId();

			// For SuperAdmin cross-collection authentication, use the collection they were authenticated against
			$sessionCollection = $user['_authenticated_collection'] ?? $collection;

			// Set session data
			$this->session->set(SessionKeys::AUTH_USER, $user['id']);
			$this->session->set(SessionKeys::AUTH_COLLECTION, $sessionCollection);
			$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, $persistentLogin);
			$this->session->delete(SessionKeys::LOGIN_ATTEMPTS);

			// If persistent login is checked, set session cookie to persist longer
			if ($persistentLogin) {
				$this->setPersistentSession();
			}

			$flash->add('success', 'Login successful');
		}

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	/**
	 * Set session cookie to persist for a longer duration when "Keep me signed in" is checked
	 * This sets the session cookie to expire in configured days instead of when browser closes.
	 */
	private function setPersistentSession(): void
	{
		$sessionName = $this->session->getName();
		$sessionId   = $this->session->getId();

		// Get configured persistent login days from config (default 30 days)
		$persistentDays = $this->config->auth['persistentLoginDays'] ?? 30;
		$expiry         = time() + ($persistentDays * 24 * 60 * 60);

		// Get session cookie parameters
		$cookieParams = session_get_cookie_params();

		// Set the session cookie with extended expiry
		setcookie(
			$sessionName,
			$sessionId,
			[
				'expires'  => $expiry,
				'path'     => $cookieParams['path'],
				'domain'   => $cookieParams['domain'],
				'secure'   => $cookieParams['secure'],
				'httponly' => $cookieParams['httponly'],
				'samesite' => $cookieParams['samesite'],
			]
		);
	}
}

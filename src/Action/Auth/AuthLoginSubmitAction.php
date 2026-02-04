<?php

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\License\Service\LicenseValidator;
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
		private PersistentLoginService $persistentLoginService,
		private LicenseValidator $licenseValidator,
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
			if ($this->session->has(SessionKeys::LOGIN_ORIGIN)) {
				$url = $this->session->get(SessionKeys::LOGIN_ORIGIN);
			}

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		$email           = $data['email'];
		$password        = $data['password'];
		$persistentLogin = !empty($data['persistent_login']);
		$collection      = $args['collection'] ?? '';

		$user = null;
		try {
			$user = $this->loginService->authenticate($email, $password, $collection);
		} catch (\Exception $e) {
			// throw new HttpUnauthorizedException($request, $e->getMessage());
			$flash->add('error', $e->getMessage());
		}

		// Check if authentication failed and redirect back
		if (!isset($user, $user['id'])) {
			if ($this->session->has(SessionKeys::LOGIN_ORIGIN)) {
				$url = $this->session->get(SessionKeys::LOGIN_ORIGIN);
			}

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Authentication succeeded - check for redirect URL in multiple places:
		// 1. POST data (from login form with redirect parameter)
		// 2. Query parameter (for direct links)
		// 3. Session storage (for direct login)
		// 4. Default to admin index
		$postData    = (array)$request->getParsedBody();
		$queryParams = $request->getQueryParams();
		$redirectUrl = $postData['redirect'] ?? $queryParams['redirect'] ?? $this->session->get(SessionKeys::REQUEST_ORIGIN_URL, $router->urlFor('admin-index'));
		$url         = $redirectUrl;

		// If license is invalid, redirect to license manager instead
		try {
			$licenseData = $this->licenseValidator->validateLicense();
			if (!$licenseData->valid) {
				$url = $router->urlFor('admin-utils', ['page' => 'license-manager']);
			}
		} catch (\Exception) {
			// If license validation fails, redirect to license manager
			$url = $router->urlFor('admin-utils', ['page' => 'license-manager']);
		}

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

		// If persistent login is checked, create persistent token
		if ($persistentLogin) {
			$this->persistentLoginService->createPersistentToken();
		}

		$flash->add('success', 'Login successful');

		return $response->withStatus(302)->withHeader('Location', $url);
	}
}

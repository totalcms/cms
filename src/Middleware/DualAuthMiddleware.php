<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Support\Config;

/**
 * Dual Authentication Middleware.
 *
 * Supports BOTH API key authentication AND session authentication.
 * Tries API key first (from Authorization header), falls back to session auth.
 */
readonly class DualAuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ApiKeyFetcher $apiKeyFetcher,
		private ResponseFactoryInterface $responseFactory,
		private PhpSession $session,
		private Config $config,
		private AccessManager $accessManager,
		private PersistentLoginService $persistentLoginService,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		// Try API key authentication first
		$apiKeyAuth = $this->tryApiKeyAuth($request);
		if ($apiKeyAuth instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			// API key is valid, add it to request attributes and proceed
			$request = $request->withAttribute('apiKey', $apiKeyAuth);
			$request = $request->withAttribute('authMethod', 'apikey');

			return $handler->handle($request);
		}

		// No valid API key, fall back to session authentication
		return $this->sessionAuth($request, $handler);
	}

	/**
	 * Try to authenticate using API key from Authorization header.
	 *
	 * @return \TotalCMS\Domain\ApiKey\Data\ApiKeyData|null Returns the API key data if valid, null otherwise
	 */
	private function tryApiKeyAuth(ServerRequestInterface $request): ?\TotalCMS\Domain\ApiKey\Data\ApiKeyData
	{
		$authHeader = $request->getHeaderLine('Authorization');

		// No Authorization header or doesn't start with Bearer
		if ($authHeader === '' || $authHeader === '0' || !str_starts_with($authHeader, 'Bearer ')) {
			return null;
		}

		$apiKey = substr($authHeader, 7); // Remove "Bearer " prefix

		if ($apiKey === '' || $apiKey === '0') {
			return null;
		}

		// Validate the API key with method and path permissions
		$method = $request->getMethod();

		// Get the route path relative to the application base
		// For example: /rw_common/plugins/stacks/tcms/collections/blog becomes /collections/blog
		$basePath = $request->getAttribute('basePath', '');
		$fullPath = $request->getUri()->getPath();
		$path     = $basePath !== '' ? substr($fullPath, strlen((string)$basePath)) : $fullPath;

		return $this->apiKeyFetcher->validateKey($apiKey, $method, $path);
	}

	/**
	 * Perform session-based authentication (existing AuthMiddleware logic).
	 */
	private function sessionAuth(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// This is the existing AuthMiddleware logic
		$this->trackSessionActivity();

		// Try to restore from persistent login if not authenticated
		if (!$this->accessManager->sessionHasUser()) {
			$restored = $this->persistentLoginService->restoreFromPersistentToken();
			if (!$restored) {
				return $this->redirectToLogin($request);
			}
		}

		// Require default auth collection
		$defaultAuthCollection = $this->config->auth['collection'];
		if (!$this->accessManager->userLoggedIn($defaultAuthCollection)) {
			return $this->redirectToDenied($request);
		}

		// Mark as session auth
		$request = $request->withAttribute('authMethod', 'session');

		return $handler->handle($request);
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirectToRoute($request, 'login');
	}

	private function redirectToDenied(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirectToRoute($request, 'denied');
	}

	private function redirectToRoute(ServerRequestInterface $request, string $route): ResponseInterface
	{
		// Set the current request URL in the session so we can send the user back to it after login
		$this->session->set(\TotalCMS\Domain\Session\SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
		$this->session->set(\TotalCMS\Domain\Session\SessionKeys::REQUEST_REFERER_URL, $request->getHeaderLine('referer'));

		// User is not logged in. Redirect to login page.
		$routeParser = \Slim\Routing\RouteContext::fromRequest($request)->getRouteParser();
		$url         = $routeParser->urlFor($route);

		return $this->responseFactory->createResponse()
			->withStatus(302)
			->withHeader('Location', $url);
	}

	private function trackSessionActivity(): void
	{
		$now  = time();
		$last = $this->session->get(\TotalCMS\Domain\Session\SessionKeys::LAST_ACTIVITY) ?? $now;
		$max  = $this->config->session['gc_maxlifetime'];

		$this->session->set(\TotalCMS\Domain\Session\SessionKeys::LAST_ACTIVITY, $now);

		if ($now - $last > $max / 4) {
			// Regenerate session ID every 4th of the max session lifetime
			$this->session->regenerateId();
		}

		// Check if session has expired
		if ($now - $last > $max) {
			// For persistent logins, extend the session lifetime instead of destroying
			if ($this->persistentLoginService->hasPersistentLogin()) {
				// Reset last activity to prevent immediate re-expiration
				$this->session->set(\TotalCMS\Domain\Session\SessionKeys::LAST_ACTIVITY, $now);
				// Clean up expired tokens periodically
				$this->persistentLoginService->cleanupExpiredTokens();
			} else {
				// Session has expired for non-persistent login. Clear and destroy the session.
				$this->session->clear();
				$this->session->destroy();
			}
		}
	}
}

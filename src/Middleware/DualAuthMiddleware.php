<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Renderer\JsonRenderer;
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
		private ApiKeyAuthenticator $authenticator,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
		private PhpSession $session,
		private Config $config,
		private AccessManager $accessManager,
		private PersistentLoginService $persistentLoginService,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		// Check if this is a public operation request (bypasses authentication)
		if ($this->isPublicRequest($request)) {
			// Mark as public submission for downstream middleware
			$request = $request->withAttribute('publicSubmission', true);

			return $handler->handle($request);
		}

		// Check if an API key was provided (regardless of validity)
		$hasApiKeyHeader = $this->authenticator->hasApiKeyHeader($request);

		// Try API key authentication first
		$apiKeyAuth = $this->authenticator->authenticate($request);
		if ($apiKeyAuth instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			// API key is valid, add it to request attributes and proceed
			$request = $request->withAttribute('apiKey', $apiKeyAuth);
			$request = $request->withAttribute('authMethod', 'apikey');

			return $handler->handle($request);
		}

		// If API key header was provided but validation failed, return JSON error
		// Don't fall back to session auth - the client is clearly making an API request
		if ($hasApiKeyHeader) {
			return $this->unauthorizedJsonResponse('Invalid API key or insufficient permissions');
		}

		// No API key provided, fall back to session authentication
		return $this->sessionAuth($request, $handler);
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

	/**
	 * Return a JSON 401 Unauthorized response.
	 */
	private function unauthorizedJsonResponse(string $message): ResponseInterface
	{
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(401),
			['error' => ['message' => $message]]
		);
	}

	/**
	 * Check if this is a public operation request that should bypass authentication.
	 *
	 * Requirements:
	 * - Must have a collection route parameter
	 * - HEAD/object-exists requests are always allowed (for ID validation)
	 * - Other operations must be in collection's publicOperations array
	 */
	private function isPublicRequest(ServerRequestInterface $request): bool
	{
		// Must have collection in route
		$collectionId = $request->getAttribute('collection');
		if (!$collectionId) {
			return false;
		}

		// HEAD requests (object-exists) are always allowed publicly
		// They only return existence (200/404) without exposing data
		// Useful for ID validation on signup forms, etc.
		$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if ($route instanceof \Slim\Interfaces\RouteInterface) {
			if ($route->getName() === 'object-exists') {
				return true;
			}
		}

		// Detect operation type from route
		$operation = $this->detectOperation($request);
		if (!$operation) {
			return false;
		}

		// Check if collection allows this operation publicly
		try {
			$collection = $this->collectionFetcher->fetchCollection((string)$collectionId);
			if (!$collection) {
				return false;
			}

			// Normalize to lowercase and check if operation is allowed
			$publicOperations = array_map('strtolower', $collection->publicOperations);

			return in_array($operation, $publicOperations, true);
		} catch (\Throwable $e) {
			// If we can't fetch the collection, don't allow public access
			return false;
		}
	}

	/**
	 * Detect the CRUD operation type based on route name.
	 *
	 * @return string|null Operation type: 'create', 'read', 'update', 'delete', or null if not a collection route
	 */
	private function detectOperation(ServerRequestInterface $request): ?string
	{
		$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return null;
		}

		$routeName = $route->getName();

		// Create operations
		if (in_array($routeName, ['object-save', 'object-clone'])) {
			return 'create';
		}

		// Read operations (object-exists is handled separately - always allowed publicly)
		if (in_array($routeName, ['collection-fetch-index', 'object-fetch', 'deck-item-fetch'])) {
			return 'read';
		}

		// Delete operations
		if ($routeName === 'object-delete') {
			return 'delete';
		}

		// Update operations (everything else on collection routes)
		$updateOperations = [
			'collection-reindex',
			'object-update',
			'object-patch',
			'property-update',
			'property-patch',
			'property-delete',
			'property-meta-update',
			'property-meta-patch',
			'deck-item-create',
			'deck-item-update',
			'deck-item-delete',
			'property-file-save',
			'property-folder-save',
			'property-clear-cache',
			'property-file-delete',
			'property-file-clear-cache',
			'property-file-move',
		];

		if (in_array($routeName, $updateOperations)) {
			return 'update';
		}

		return null;
	}
}

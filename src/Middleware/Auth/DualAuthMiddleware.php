<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Session\SessionKeys;
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
		private OperationDetector $operationDetector,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		// Check if this is a public operation request (bypasses authentication)
		if ($this->isPublicCollectionRequest($request)) {
			// Mark as public submission for downstream middleware
			$request = $request->withAttribute('publicSubmission', true);

			return $handler->handle($request);
		}

		$method = $request->getMethod();
		if ($method === 'HEAD') {
			return $handler->handle($request);
		}

		// Check if an API key was provided (regardless of validity)
		$hasApiKeyHeader = $this->authenticator->hasApiKeyHeader($request);

		// Try API key authentication first
		$apiKeyAuth = $this->authenticator->authenticate($request);
		if ($apiKeyAuth instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			// Check if External REST API feature is available for current edition
			if (!$this->editionFeatures->can(EditionFeature::EXTERNAL_REST_API)) {
				$edition = $this->editionFeatures->getEdition();

				return $this->jsonRenderer->json(
					$this->responseFactory->createResponse()->withStatus(403),
					['error' => [
						'message'  => 'External REST API access requires the Pro edition or higher.',
						'edition'  => $edition->value,
						'required' => 'pro',
					]]
				);
			}

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

		// Check if user is logged in (any auth collection)
		// Pass empty string to accept any authenticated user, regardless of which collection they're in
		if (!$this->accessManager->userLoggedIn('')) {
			return $this->redirectToDenied($request);
		}

		// Mark as session auth
		$request = $request->withAttribute('authMethod', 'session');

		return $handler->handle($request);
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// API requests get JSON 401 response
		if (!str_starts_with($path, '/admin/')) {
			return $this->unauthorizedJsonResponse('Authentication required');
		}

		return $this->redirectToRoute($request, 'login');
	}

	private function redirectToDenied(ServerRequestInterface $request): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// API requests get JSON 403 response
		if (!str_starts_with($path, '/admin/')) {
			return $this->jsonRenderer->json(
				$this->responseFactory->createResponse()->withStatus(403),
				['error' => ['message' => 'Access denied']]
			);
		}

		return $this->redirectToRoute($request, 'denied');
	}

	private function redirectToRoute(ServerRequestInterface $request, string $route): ResponseInterface
	{
		// Set the current request URL in the session so we can send the user back to it after login
		$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
		$this->session->set(SessionKeys::REQUEST_REFERER_URL, $request->getHeaderLine('referer'));

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
		$last = $this->session->get(SessionKeys::LAST_ACTIVITY) ?? $now;
		$max  = $this->config->session['gc_maxlifetime'];

		// Check for persistent login - use hasPersistentLoginOrCookie() to handle
		// the case where session was garbage collected but cookie still exists
		$isPersistentLogin = $this->persistentLoginService->hasPersistentLoginOrCookie();

		$this->session->set(SessionKeys::LAST_ACTIVITY, $now);

		if ($now - $last > $max / 4) {
			// Regenerate session ID every 4th of the max session lifetime
			$this->session->regenerateId();
		}

		// Check if session has expired
		if ($now - $last > $max) {
			if ($isPersistentLogin) {
				// Clean up expired tokens periodically
				$this->persistentLoginService->cleanupExpiredTokens();

				return;
			}
			// Session has expired for non-persistent login. Clear and destroy the session.
			$this->session->clear();
			$this->session->destroy();
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
	private function isPublicCollectionRequest(ServerRequestInterface $request): bool
	{
		// Must have collection in route - get from route arguments, not request attributes
		$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return false;
		}

		$collectionId = $route->getArgument('collection');
		if ($collectionId === null || $collectionId === '') {
			return false;
		}

		$method = $request->getMethod();
		if ($method === 'HEAD') {
			return true;
		}

		// Detect operation type from route
		$operation = $this->operationDetector->detectPublicOperation($request);
		if ($operation === null) {
			return false;
		}

		// Check if collection allows this operation publicly
		try {
			$collection = $this->collectionFetcher->fetchCollection($collectionId);
			if (!$collection instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
				return false;
			}

			// Normalize to lowercase and check if operation is allowed
			$publicOperations = array_map(strtolower(...), $collection->publicOperations);

			return in_array($operation, $publicOperations, true);
		} catch (\Throwable) {
			// If we can't fetch the collection, don't allow public access
			return false;
		}
	}
}

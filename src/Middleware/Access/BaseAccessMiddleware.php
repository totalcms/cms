<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Base Access Middleware.
 *
 * Provides common access control logic for all resource-specific middleware.
 * Enforces access group permissions with HTML/JSON error responses.
 * API keys bypass access group checks (trust model).
 */
abstract readonly class BaseAccessMiddleware implements MiddlewareInterface
{
	/**
	 * Resource name for error messages (e.g., 'collection', 'schema', 'template').
	 * Override in concrete classes.
	 */
	protected const RESOURCE_NAME = 'resource';

	public function __construct(
		protected UserValidationService $userValidation,
		protected AccessControlService $accessControl,
		protected SessionInterface $session,
		protected JsonRenderer $jsonRenderer,
		protected TwigRenderer $twigRenderer,
		protected ResponseFactoryInterface $responseFactory,
		protected Config $config,
		protected OperationDetector $operationDetector,
		protected LoggerFactory $loggerFactory,
		protected OAuthActivityLogger $oauthActivityLogger,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if (!$this->config->authEnabled()) {
			return $handler->handle($request);
		}

		// Public submissions bypass access control (already validated by DualAuthMiddleware)
		if ($request->getAttribute('publicSubmission') === true) {
			return $handler->handle($request);
		}

		$authMethod = $request->getAttribute('authMethod');

		// API keys bypass group checks entirely (trust model).
		if ($authMethod === 'apikey') {
			return $handler->handle($request);
		}

		$isOAuthBearer = $authMethod === 'oauth_bearer';

		if ($isOAuthBearer) {
			// OAuth Bearer requests were already scope-checked by OAuthRestScopeMiddleware
			// before reaching here. There is no PHP session for Bearer requests, so the
			// caller's identity + access groups are resolved from the token's `sub` claim
			// via AccessControlService::authorityFor() (session-free) instead of the
			// session-coupled lookups the rest of this class uses. Admins get the same
			// unrestricted bypass session-authenticated admins get; everyone else falls
			// through to the SAME per-operation checkPermission() session users go
			// through, with $userId resolved from the token and the resolved
			// UserAuthority threaded through via the `accessAuthority` request
			// attribute so checkPermission() implementations can use it instead of
			// re-deriving groups from the (absent) session.
			$ref       = OAuthUserRef::parse((string)$request->getAttribute('oauth_user_id', ''), (string)$this->config->auth['collection']);
			$authority = $this->accessControl->authorityFor($ref);
			if ($authority->isAdmin) {
				return $handler->handle($request);
			}

			$userId  = $ref->userId;
			$request = $request->withAttribute('accessAuthority', $authority);
		} else {
			// Get user ID from session
			$sessionUserId = $this->session->get(SessionKeys::AUTH_USER);
			if (!$sessionUserId) {
				return $this->forbiddenResponse($request, 'Authentication required');
			}
			$userId         = (string)$sessionUserId;
			$userCollection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');

			// Super admins bypass all access checks
			if ($this->userValidation->isSuperAdmin($userId, $userCollection)) {
				return $handler->handle($request);
			}
		}

		// Detect CRUD operation for permission checking
		$operation = $this->detectOperation($request);
		if (!$operation) {
			// Unable to detect operation, deny access and log in dev/debug mode
			if ($this->config->env === 'dev' || $this->config->debug) {
				$logger       = $this->loggerFactory->channelLogger(LogChannel::Access);
				$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
				$route        = $routeContext->getRoute();
				$routeName    = $route instanceof \Slim\Interfaces\RouteInterface ? $route->getName() : 'unknown';

				$logger->warning('Operation detection failed', [
					'resource'   => static::RESOURCE_NAME,
					'route_name' => $routeName,
					'path'       => $request->getUri()->getPath(),
					'method'     => $request->getMethod(),
					'user_id'    => $userId,
				]);
			}

			if ($isOAuthBearer) {
				$this->logGroupRejection($request, $userId, 'unknown');
			}

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		// Check resource-specific permissions (implemented by concrete classes)
		$hasAccess = $this->checkPermission($userId, $operation, $request);

		if ($hasAccess === false) {
			if ($isOAuthBearer) {
				$this->logGroupRejection($request, $userId, $operation);
			}

			$this->logDenial($request, $userId, $operation, $isOAuthBearer);

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		return $handler->handle($request);
	}

	/**
	 * Record every access-group denial to the access log.
	 *
	 * The response deliberately says only "Access denied" (or a slightly fuller
	 * sentence in dev), so without this there is NO server-side record of who was
	 * refused what: logGroupRejection() below covers only OAuth Bearer callers,
	 * leaving session users — i.e. everyone using the admin — completely silent.
	 * Diagnosing "my editor cannot save their own profile" then means reading the
	 * session off disk to work out which comparison failed. Route name and
	 * operation are the two facts that identify the check that refused.
	 */
	private function logDenial(ServerRequestInterface $request, string $userId, string $operation, bool $isOAuthBearer): void
	{
		// Read the route attribute directly rather than through
		// RouteContext::fromRequest(), which throws when routing metadata is
		// absent. This runs on the denial path: a throw here would turn a clean
		// 403 into a 500, which is a worse outcome than an unnamed log line.
		$route     = $request->getAttribute(\Slim\Routing\RouteContext::ROUTE);
		$routeName = $route instanceof \Slim\Interfaces\RouteInterface ? (string)$route->getName() : 'unknown';

		$this->loggerFactory->channelLogger(LogChannel::Access)->warning('Access denied', [
			'resource'    => static::RESOURCE_NAME,
			'operation'   => $operation,
			'user_id'     => $userId === '' ? '(none)' : $userId,
			'auth_method' => $isOAuthBearer ? 'oauth_bearer' : 'session',
			'route_name'  => $routeName,
			'method'      => $request->getMethod(),
			'path'        => $request->getUri()->getPath(),
		]);
	}

	/**
	 * Record a group-layer denial to the oauth-activity log, distinct from
	 * OAuthRestScopeMiddleware's scopeRejected() which fires earlier for
	 * tokens whose scopes don't cover the operation at all.
	 */
	private function logGroupRejection(ServerRequestInterface $request, string $userId, string $operation): void
	{
		$clientId = (string)$request->getAttribute('oauth_client_id', '');
		$this->oauthActivityLogger->groupRejected($clientId, $operation, $userId);
	}

	/**
	 * Check if the user has permission to access the requested resource.
	 * Implemented by concrete middleware classes.
	 *
	 * For OAuth Bearer callers, $userId is the token's subject (not a
	 * session user) and $request carries a resolved `accessAuthority`
	 * (TotalCMS\Domain\Auth\Data\UserAuthority) attribute — implementations
	 * that need per-collection/schema group data should prefer that
	 * attribute over AccessControlService's session-coupled canAccessX()
	 * methods when present, since there is no PHP session to read from on
	 * a Bearer request.
	 *
	 * @param string $userId User ID from session (or OAuth token subject)
	 * @param string $operation CRUD operation (create, read, update, delete)
	 * @param ServerRequestInterface $request HTTP request
	 *
	 * @return bool True if access allowed, false otherwise
	 */
	abstract protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool;

	/**
	 * Resolve the CRUD operation for this request. The default maps the
	 * route name through OperationDetector's explicit lists; subclasses
	 * guarding routes that aren't in those lists (e.g. the extension admin
	 * dispatcher's single catch-all route) can override with their own
	 * mapping instead of being denied on detection failure.
	 */
	protected function detectOperation(ServerRequestInterface $request): ?string
	{
		return $this->operationDetector->detectOperation($request);
	}

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	protected function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// Admin UI requests should get HTML response
		if (str_starts_with($path, '/admin/')) {
			$details = $this->config->env === 'dev'
				? sprintf("Path: %s\nMethod: %s\nUser: %s", $path, $request->getMethod(), $this->session->get(SessionKeys::AUTH_USER) ?? 'none')
				: null;

			return $this->twigRenderer->template(
				$this->responseFactory->createResponse()->withStatus(403),
				'access-denied.twig',
				[
					'message'  => $message,
					'details'  => $details,
					'referrer' => $request->getHeaderLine('Referer') ?: null,
				]
			);
		}

		// API requests get JSON response
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(403),
			['error' => ['message' => $message]]
		);
	}

	/**
	 * Get error message based on environment.
	 */
	protected function getErrorMessage(): string
	{
		$isDev = $this->config->env === 'dev';

		return $isDev
			? sprintf('Access denied: Your access groups do not have permission to perform this action on this %s', static::RESOURCE_NAME)
			: 'Access denied';
	}
}

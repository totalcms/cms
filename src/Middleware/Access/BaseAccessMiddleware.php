<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\AccessActor;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\ForbiddenRenderer;
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

	/**
	 * Request attribute carrying the {@see AccessActor}: the acting user id and
	 * auth collection, resolved once here and read by every later layer.
	 * Attached for bearer and session requests before the admin bypass, and for
	 * a public submission made by a signed-in session user (so AuthFieldPolicy
	 * still stamps them as owner); API-key, anonymous public-submission and
	 * auth-disabled requests carry none.
	 */
	public const ACTOR_ATTRIBUTE = 'accessActor';

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
			return $handler->handle($this->withSessionActor($request));
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
			$request   = $request->withAttribute(self::ACTOR_ATTRIBUTE, new AccessActor($ref->userId, $ref->collection));
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
			$request        = $request->withAttribute(self::ACTOR_ATTRIBUTE, new AccessActor($userId, $userCollection));

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
				$routeContext = RouteContext::fromRequest($request);
				$route        = $routeContext->getRoute();
				$routeName    = $route instanceof RouteInterface ? $route->getName() : 'unknown';

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

			$this->logDenial($request, $userId, $operation, $isOAuthBearer, $this->denialContext($request, $userId, $operation));

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		return $handler->handle($this->scopeRequest($request, $userId, $operation));
	}

	/**
	 * A public submission skips access control, but a signed-in session user
	 * making one is still the actor PrivilegedFieldGuard applies the field
	 * policy for: attach them when there is one, and nothing when the caller
	 * is anonymous.
	 */
	private function withSessionActor(ServerRequestInterface $request): ServerRequestInterface
	{
		$userId = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		if ($userId === '') {
			return $request;
		}

		return $request->withAttribute(self::ACTOR_ATTRIBUTE, new AccessActor($userId, (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '')));
	}

	/**
	 * The acting user resolved by process(), or an anonymous actor when the
	 * request carries none.
	 */
	protected function actorOf(ServerRequestInterface $request): AccessActor
	{
		$actor = $request->getAttribute(self::ACTOR_ATTRIBUTE);

		return $actor instanceof AccessActor ? $actor : new AccessActor('', '');
	}

	/**
	 * Hook for a subclass to narrow an allowed request before the action runs,
	 * e.g. by attaching a request attribute the action applies as a filter.
	 * The default passes the request through untouched. Only called once
	 * checkPermission() has allowed the request; super admins, API keys and
	 * disabled auth never reach it.
	 */
	protected function scopeRequest(ServerRequestInterface $request, string $userId, string $operation): ServerRequestInterface
	{
		return $request;
	}

	/**
	 * Hook for a subclass to say WHY checkPermission() refused — merged into
	 * the access-log context of the denial. The default adds nothing.
	 *
	 * @return array<string,mixed>
	 */
	protected function denialContext(ServerRequestInterface $request, string $userId, string $operation): array
	{
		return [];
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
	 *
	 * @param array<string,mixed> $extra subclass-supplied context, e.g. the reason
	 */
	private function logDenial(ServerRequestInterface $request, string $userId, string $operation, bool $isOAuthBearer, array $extra = []): void
	{
		// Read the route attribute directly rather than through
		// RouteContext::fromRequest(), which throws when routing metadata is
		// absent. This runs on the denial path: a throw here would turn a clean
		// 403 into a 500, which is a worse outcome than an unnamed log line.
		$route     = $request->getAttribute(RouteContext::ROUTE);
		$routeName = $route instanceof RouteInterface ? (string)$route->getName() : 'unknown';

		$this->loggerFactory->channelLogger(LogChannel::Access)->warning('Access denied', array_merge([
			'resource'    => static::RESOURCE_NAME,
			'operation'   => $operation,
			'user_id'     => $userId === '' ? '(none)' : $userId,
			'auth_method' => $isOAuthBearer ? 'oauth_bearer' : 'session',
			'route_name'  => $routeName,
			'method'      => $request->getMethod(),
			'path'        => $request->getUri()->getPath(),
		], $extra));
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
		$details = $this->config->env === 'dev'
			? sprintf("Path: %s\nMethod: %s\nUser: %s", $request->getUri()->getPath(), $request->getMethod(), $this->session->get(SessionKeys::AUTH_USER) ?? 'none')
			: null;

		return (new ForbiddenRenderer($this->twigRenderer, $this->jsonRenderer, $this->responseFactory))
			->forbidden($request, $message, $details);
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

<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\ForbiddenRenderer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Base Access Middleware.
 *
 * Resolves who is calling into an {@see Actor} — from the API key, the OAuth
 * token or the session — and makes it the request's current actor in
 * {@see ActorContext}, where the storage layer and every later check read it.
 * Trusted actors (API keys, super admins, admin tokens) pass; everyone else
 * goes through the resource-specific {@see checkPermission()}, which asks the
 * actor's access-group authority. Answers a refusal with an HTML or JSON 403.
 */
abstract readonly class BaseAccessMiddleware implements MiddlewareInterface
{
	/**
	 * Resource name for error messages (e.g., 'collection', 'schema', 'template').
	 * Override in concrete classes.
	 */
	protected const RESOURCE_NAME = 'resource';

	public function __construct(
		protected ActorResolver $actorResolver,
		protected ActorContext $actors,
		protected JsonRenderer $jsonRenderer,
		protected TwigRenderer $twigRenderer,
		protected ResponseFactoryInterface $responseFactory,
		protected Config $config,
		protected OperationDetector $operationDetector,
		protected LoggerFactory $loggerFactory,
		protected AccessDenials $denials,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if (!$this->config->authEnabled()) {
			return $handler->handle($request);
		}

		$actor = $this->resolveActor($request);
		$this->actors->set($actor);

		// Public submissions bypass access control (already validated by
		// DualAuthMiddleware). The actor is still set: a signed-in user making
		// one is stamped as the owner and held to the field policy.
		if ($request->getAttribute('publicSubmission') === true) {
			return $handler->handle($request);
		}

		// API keys, super admins and admin tokens pass every check.
		if ($actor->isTrusted()) {
			return $handler->handle($request);
		}

		if ($actor->kind === ActorKind::Anonymous) {
			return $this->forbiddenResponse($request, 'Authentication required');
		}

		// Detect CRUD operation for permission checking
		$operation = $this->detectOperation($request);
		if (!$operation) {
			// Unable to detect operation, deny access and log in dev/debug mode
			if ($this->config->env === 'dev' || $this->config->debug) {
				$route = $request->getAttribute(RouteContext::ROUTE);

				$this->loggerFactory->channelLogger(LogChannel::Access)->warning('Operation detection failed', [
					'resource'   => static::RESOURCE_NAME,
					'route_name' => $route instanceof RouteInterface ? $route->getName() : 'unknown',
					'path'       => $request->getUri()->getPath(),
					'method'     => $request->getMethod(),
					'user_id'    => $actor->userId,
				]);
			}

			if ($actor->isBearer()) {
				$this->denials->groupRejected($request, $actor, 'unknown');
			}

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		if (!$this->checkPermission($actor, $operation, $request)) {
			if ($actor->isBearer()) {
				$this->denials->groupRejected($request, $actor, $operation);
			}

			$this->denials->request($request, $actor, static::RESOURCE_NAME, $operation, $this->denialContext($request, $actor, $operation));

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		return $handler->handle($this->scopeRequest($request, $actor, $operation));
	}

	/**
	 * The caller, by the credential the auth middleware recognized: an API
	 * key, an OAuth token (OAuthRestScopeMiddleware has already checked its
	 * scopes), or the session.
	 */
	private function resolveActor(ServerRequestInterface $request): Actor
	{
		return match ($request->getAttribute('authMethod')) {
			'apikey'       => Actor::apiKey(self::apiKeyIdOf($request)),
			'oauth_bearer' => $this->actorResolver->fromBearer(
				(string)$request->getAttribute('oauth_user_id', ''),
				ActorResolver::scopeIds($request->getAttribute('oauth_scopes')),
				(string)$request->getAttribute('oauth_client_id', ''),
			),
			default => $this->actorResolver->fromSession(),
		};
	}

	/**
	 * The id of the API key the auth middleware validated, for the record.
	 */
	private static function apiKeyIdOf(ServerRequestInterface $request): string
	{
		$apiKey = $request->getAttribute('apiKey');

		return $apiKey instanceof ApiKeyData ? $apiKey->id : '';
	}

	/**
	 * Hook for a subclass to narrow an allowed request before the action runs,
	 * e.g. by attaching a request attribute the action applies as a filter.
	 * The default passes the request through untouched. Only called once
	 * checkPermission() has allowed the request; trusted actors and disabled
	 * auth never reach it.
	 */
	protected function scopeRequest(ServerRequestInterface $request, Actor $actor, string $operation): ServerRequestInterface
	{
		return $request;
	}

	/**
	 * Hook for a subclass to say WHY checkPermission() refused — merged into
	 * the access-log context of the denial. The default adds nothing.
	 *
	 * @return array<string,mixed>
	 */
	protected function denialContext(ServerRequestInterface $request, Actor $actor, string $operation): array
	{
		return [];
	}

	/**
	 * Whether this signed-in, non-admin actor may perform $operation on the
	 * requested resource. Implementations ask `$actor->authority()`, which
	 * holds the actor's access-group grants whether they arrived by session or
	 * by OAuth token.
	 *
	 * @param string $operation CRUD operation (create, read, update, delete)
	 */
	abstract protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool;

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
		$userId  = $this->actors->current()->userId;
		$details = $this->config->env === 'dev'
			? sprintf("Path: %s\nMethod: %s\nUser: %s", $request->getUri()->getPath(), $request->getMethod(), $userId !== '' ? $userId : 'none')
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

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\AccessDeniedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Object\Service\ObjectWriteGate;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The record of every refusal: a line in the access log and the
 * `access.denied` core event, so an audit log or an automation sees each one.
 *
 * A refusal answers only "Access denied" (or a slightly fuller sentence in
 * dev), so without this there is no server-side record of who was refused
 * what. Used by the access middleware for a refused request and by
 * {@see ObjectWriteGate} for a write the
 * storage layer refused.
 */
final readonly class AccessDenials
{
	public function __construct(
		private LoggerFactory $loggerFactory,
		private OAuthActivityLogger $oauthActivityLogger,
		private EventDispatcher $events,
	) {
	}

	/**
	 * A request the access middleware refused.
	 *
	 * @param string              $resource what was asked for: `collection`, `schema`…
	 * @param array<string,mixed> $extra    the refusing check's context, e.g. its `reason`
	 */
	public function request(ServerRequestInterface $request, Actor $actor, string $resource, string $operation, array $extra = []): void
	{
		// Read the route attribute directly rather than through
		// RouteContext::fromRequest(), which throws when routing metadata is
		// absent. This runs on the denial path: a throw here would turn a clean
		// 403 into a 500, which is a worse outcome than an unnamed log line.
		$route     = $request->getAttribute(RouteContext::ROUTE);
		$route     = $route instanceof RouteInterface ? $route : null;
		$routeName = $route?->getName() ?? 'unknown';

		$this->loggerFactory->channelLogger(LogChannel::Access)->warning('Access denied', array_merge([
			'resource'    => $resource,
			'operation'   => $operation,
			'user_id'     => $actor->userId === '' ? '(none)' : $actor->userId,
			'auth_method' => $this->authMethod($actor),
			'route_name'  => $routeName,
			'method'      => $request->getMethod(),
			'path'        => $request->getUri()->getPath(),
		], $extra));

		$this->events->dispatch(CoreEvent::ACCESS_DENIED, new AccessDeniedPayload(
			resource: $resource,
			operation: $operation,
			reason: is_string($extra['reason'] ?? null) ? $extra['reason'] : '',
			collection: (string)$route?->getArgument('collection', ''),
			id: (string)$route?->getArgument('id', ''),
			route: $routeName,
			method: $request->getMethod(),
			path: $request->getUri()->getPath(),
		));
	}

	/**
	 * A write the storage layer refused: the request's own checks let it
	 * through, which the access log should show.
	 */
	public function write(Actor $actor, string $collection, string $id, Operation $operation, Decision $decision): void
	{
		$this->loggerFactory->channelLogger(LogChannel::Access)->warning('Write refused at storage', [
			'resource'    => 'object',
			'operation'   => $operation->value,
			'reason'      => $decision->reason(),
			'collection'  => $collection,
			'id'          => $id,
			'user_id'     => $actor->userId === '' ? '(none)' : $actor->userId,
			'auth_method' => $this->authMethod($actor),
		]);

		$this->events->dispatch(CoreEvent::ACCESS_DENIED, new AccessDeniedPayload('object', $operation->value, $decision->reason(), $collection, $id));
	}

	/**
	 * A token's group-layer refusal, to the oauth-activity log: distinct from
	 * OAuthRestScopeMiddleware's scopeRejected(), which fires earlier for
	 * tokens whose scopes don't cover the operation at all.
	 */
	public function groupRejected(ServerRequestInterface $request, Actor $actor, string $operation): void
	{
		$this->oauthActivityLogger->groupRejected((string)$request->getAttribute('oauth_client_id', ''), $operation, $actor->userId);
	}

	private function authMethod(Actor $actor): string
	{
		return match (true) {
			$actor->kind === ActorKind::ApiKey => 'apikey',
			$actor->isBearer()                 => 'oauth_bearer',
			default                            => 'session',
		};
	}
}

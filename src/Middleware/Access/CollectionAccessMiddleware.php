<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Object\Service\ObjectWriteGate;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Collection Access Middleware: the REST adapter for {@see AccessGuard}.
 *
 * It translates a collection route into the guard's terms — collection,
 * {@see Operation}, object id, property — and refuses with a 403 when the
 * guard says no. Every rule lives in the guard; what lives here is only the
 * mapping from route names onto operations:
 *
 *   - `object-bulk-delete`, `collection-reindex`, `object-clone` and the
 *     `import-*` routes are their own operations;
 *   - the admin page's pseudo-objects (`-export`, `-import`, `-factory`,
 *     `-sitemap`, `-feeds`) read across every object: Export;
 *   - the admin add form posting a `duplicate` field is a Clone;
 *   - `object-save` and the admin `add` form are a Create without an id;
 *   - the two list routes and the admin collection page are a List;
 *   - any other route without an `{id}` (exports, reports) is an Export;
 *   - everything else is the CRUD operation OperationDetector found.
 *
 * Checks run in this order, and the reason of the first refusal is logged:
 * the access groups, the field policy on a `{property}` write route (a
 * protected property is admin-only on every route), ownership, then the
 * clone and import refusals on collections with protected fields. A clone
 * whose body names a target `collection` other than the route's is refused
 * too: only the route collection is access-checked.
 *
 * The list routes are handed the actor's {@see READ_SCOPE_ATTRIBUTE}, which
 * IndexQueryAction and IndexGetAction apply as a filter. Protected and
 * forced fields need nothing from here: the storage layer applies them to
 * every write ({@see ObjectWriteGate}).
 */
readonly class CollectionAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection';

	/**
	 * Request attribute carrying the actor's {@see ReadScope} for the list
	 * actions, which keep only the items it matches. Absent when the actor
	 * may read everything.
	 */
	public const READ_SCOPE_ATTRIBUTE = 'readScope';

	/** Routes that are an operation of their own rather than plain CRUD. */
	private const ROUTE_OPERATIONS = [
		'object-bulk-delete' => Operation::BulkDelete,
		'collection-reindex' => Operation::Reindex,
		'object-clone'       => Operation::Clone,
	];

	/** Id-less routes that list the collection (scoped to the actor's own objects). */
	private const LIST_ROUTES = ['collection-query', 'collection-fetch-index', 'admin-collection'];

	/** List routes whose actions apply the owner scope themselves. */
	private const OWNER_SCOPED_LIST_ROUTES = ['collection-query', 'collection-fetch-index'];

	/** Operations whose `{property}` routes write the named property. */
	private const PROPERTY_WRITE_OPERATIONS = ['create', 'update', 'delete'];

	public function __construct(
		ActorResolver $actorResolver,
		ActorContext $actors,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		AccessDenials $denials,
		private AccessGuard $guard,
	) {
		parent::__construct($actorResolver, $actors, $jsonRenderer, $twigRenderer, $responseFactory, $config, $operationDetector, $loggerFactory, $denials);
	}

	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		$route = $this->routeOf($request);
		if (!$route instanceof RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		return $this->decide($actor, $operation, $request, $route) === Decision::Allow;
	}

	/**
	 * The guard's verdict for this route, the first refusal winning. NotFound
	 * (a read of a missing object) counts as allowed: the action answers 404.
	 */
	private function decide(Actor $actor, string $operation, ServerRequestInterface $request, RouteInterface $route): Decision
	{
		$collection = (string)$route->getArgument('collection', '');
		$objectId   = (string)$route->getArgument('id', '');

		if (!$this->guard->groupAllows($actor, $collection, $operation, $objectId)) {
			return Decision::DenyGroup;
		}

		$property = (string)$route->getArgument('property', '');
		if ($collection !== '' && $property !== '' && in_array($operation, self::PROPERTY_WRITE_OPERATIONS, true)
			&& !$this->guard->canWriteProperty($actor, $collection, $property)) {
			return Decision::DenyProtected;
		}

		[$domainOperation, $id] = $this->operationOf($operation, $request, $route, $objectId);

		if (!$this->guard->ownerDecision($actor, $collection, $domainOperation, $id)->passes()) {
			return Decision::DenyOwner;
		}

		if ($domainOperation === Operation::Clone && self::cloneTargetsOtherCollection($request, $collection)) {
			return Decision::DenyProtected;
		}

		return $this->guard->copyAllows($actor, $collection, $domainOperation) ? Decision::Allow : Decision::DenyProtected;
	}

	/**
	 * The domain operation a route performs, and the object id it names (''
	 * for the admin page's `add` keyword and pseudo-objects, which name none).
	 *
	 * @return array{0: Operation, 1: string}
	 */
	private function operationOf(string $operation, ServerRequestInterface $request, RouteInterface $route, string $objectId): array
	{
		$routeName = (string)$route->getName();

		if (isset(self::ROUTE_OPERATIONS[$routeName])) {
			return [self::ROUTE_OPERATIONS[$routeName], $objectId];
		}

		if (str_starts_with($routeName, 'import-')) {
			return [Operation::Import, ''];
		}

		if ($routeName === 'admin-collection' || $routeName === 'admin-collection-post') {
			$adminOperation = $this->adminPageOperation($routeName, $objectId, $request);
			if ($adminOperation instanceof Operation) {
				return [$adminOperation, ''];
			}
		}

		if ($objectId === '') {
			return match (true) {
				$routeName === 'object-save'                    => [Operation::Create, ''],
				in_array($routeName, self::LIST_ROUTES, true)   => [Operation::List, ''],
				default                                         => [Operation::Export, ''],
			};
		}

		return [Operation::tryFrom($operation) ?? Operation::Export, $objectId];
	}

	/**
	 * What an admin collection page request does when it is not a plain
	 * object page: a pseudo-object works across every object, the `add`
	 * keyword creates, and a `duplicate` field on the add form clones (any
	 * string value, even "0", which is what AdminCollectionAction acts on).
	 * Null for an ordinary object page.
	 */
	private function adminPageOperation(string $routeName, string $objectId, ServerRequestInterface $request): ?Operation
	{
		if (str_starts_with($objectId, '-')) {
			return Operation::Export;
		}

		if ($routeName === 'admin-collection-post') {
			$body = (array)$request->getParsedBody();
			if (isset($body['duplicate']) && is_string($body['duplicate'])) {
				return Operation::Clone;
			}
		}

		return $objectId === 'add' && $routeName === 'admin-collection-post' ? Operation::Create : null;
	}

	/**
	 * Whether a clone request's body names a target `collection` that is not
	 * identical to the route collection (any non-null value, of any type).
	 * ObjectCloneAction copies into the body's collection when one is given.
	 * Shared with DualAuthMiddleware's public path.
	 */
	public static function cloneTargetsOtherCollection(ServerRequestInterface $request, string $routeCollection): bool
	{
		$target = ((array)$request->getParsedBody())['collection'] ?? null;

		// Any non-null value counts: the action passes it through untouched
		// (`$data['collection'] ?? $route`), so 2024 becomes "2024", true
		// becomes "1", and an explicit "" is used as-is rather than falling
		// back to the route collection.
		return $target !== null && $target !== $routeCollection;
	}

	/**
	 * Hand the two list routes of an owned collection the actor's owner scope;
	 * every other request passes untouched.
	 */
	protected function scopeRequest(ServerRequestInterface $request, Actor $actor, string $operation): ServerRequestInterface
	{
		$route = $this->routeOf($request);
		if (!$route instanceof RouteInterface || !in_array((string)$route->getName(), self::OWNER_SCOPED_LIST_ROUTES, true)) {
			return $request;
		}

		// A public read never reaches this middleware (DualAuthMiddleware
		// marks it a public submission), so no published objects are added.
		$scope = $this->guard->readScope($actor, (string)$route->getArgument('collection', ''));

		return $scope->isAll() ? $request : $request->withAttribute(self::READ_SCOPE_ATTRIBUTE, $scope);
	}

	/**
	 * Which check refused: `group`, `protected` or `owner`.
	 *
	 * @return array<string,mixed>
	 */
	protected function denialContext(ServerRequestInterface $request, Actor $actor, string $operation): array
	{
		$route = $this->routeOf($request);

		return $route instanceof RouteInterface ? ['reason' => $this->decide($actor, $operation, $request, $route)->reason()] : [];
	}

	/**
	 * The matched route, read from the attribute directly so a request
	 * without routing metadata yields null rather than a throw.
	 */
	private function routeOf(ServerRequestInterface $request): ?RouteInterface
	{
		$route = $request->getAttribute(RouteContext::ROUTE);

		return $route instanceof RouteInterface ? $route : null;
	}
}

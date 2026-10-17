<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Collection Access Middleware.
 *
 * Enforces access group permissions for collection operations, and on an
 * owned collection (one whose `ownerProperty` names a property the schema
 * defines) limits every non-super-admin to the objects they own:
 *
 *   - an empty actor id is denied outright;
 *   - routes with an `{id}` deny unless the object's owner is the actor; a
 *     missing object passes for a read only (the action 404s);
 *   - `collection-query` / `collection-fetch-index` are handed an
 *     `ownerScope` request attribute that the actions apply as a filter;
 *   - `object-bulk-delete`, `collection-reindex` and `object-clone` are
 *     refused — bulk and reindex cannot be scoped, clone would copy
 *     another object's owner (clone is also refused on any collection with
 *     protected fields; see below);
 *   - `admin-collection-post` with a `duplicate` field (admin clone) is refused;
 *   - the admin pseudo-pages (`admin-collection` / `admin-collection-post`
 *     with an id starting `-`: export, import, factory, sitemap, feeds) are
 *     refused;
 *   - id-less routes are an allow-list: only `object-save` (AuthFieldPolicy
 *     stamps the owner on create), the two list routes and `admin-collection`
 *     pass. Export, report and import routes are refused.
 *
 * On EVERY collection, owned or not, a write route that names a `{property}`
 * (property delete, deck items, files, folders, meta, counters, uploads) is
 * refused when {@see AuthFieldPolicy::canWriteProperty()} says the actor may
 * not write that property: a protected property is admin-only on every route.
 * Likewise `object-clone` is refused (`reason: protected`) on any collection
 * with a protected, forced or owner rule: a clone copies values the actor
 * could not write. On an owned collection the owner check refuses it first.
 * A clone whose body names a target `collection` other than the route's is
 * refused too: only the route collection is access-checked. The collection
 * imports (`import-*`) are refused (`reason: protected`) on the same
 * collections: they upsert whole objects and no field policy runs on them.
 *
 * After allowing a create, update or delete on a route that names both a
 * `{collection}` and an `{id}` (other than a counter increment or decrement,
 * which is not a content edit), it remembers in {@see ForcedValueContext}
 * what AuthFieldPolicy forces on that object (`protected: {"value": X}`).
 * ObjectUpdater::updateObject() lays those values over every save of the
 * object in this request, so deck items, files, uploads, meta writes and
 * property deletes cannot skip them.
 *
 * Super admins, API keys and admin bearer tokens exit BaseAccessMiddleware
 * before any of this. The group check runs first and neither ownership nor
 * the field policy ever grants what the group did not.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") the base already takes
 * ten container services; the four added here are the owner lookups, the
 * field policy and the forced-value context.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the owner scope value
 * object is the twenty-first type; every dependency is a container service
 * or data type this one access decision needs.
 */
readonly class CollectionAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection';

	/**
	 * Request attribute carrying an {@see OwnerScope} for the list actions,
	 * which keep only the items whose owner property equals the actor's id
	 * exactly.
	 */
	public const OWNER_SCOPE_ATTRIBUTE = 'ownerScope';

	/** Operations that cannot be limited to the actor's own objects. */
	private const OWNER_DENIED_ROUTES = ['object-bulk-delete', 'collection-reindex', 'object-clone'];

	/**
	 * The only id-less routes a scoped actor may use: object-save (the field
	 * policy stamps the owner), the two list routes (scoped by the ownerScope
	 * attribute) and the admin collection page (it loads its rows through the
	 * scoped collection-query). Every other route without an {id} (export,
	 * report, import, ...) reads or writes across owners and is refused.
	 */
	private const OWNER_ALLOWED_IDLESS_ROUTES = ['object-save', 'collection-query', 'collection-fetch-index', 'admin-collection'];

	/**
	 * Counter routes: a tick is not a content edit, so it does not re-apply
	 * forced values (an approved object stays approved). propertyAllows()
	 * still refuses a protected counter.
	 */
	private const COUNTER_ROUTES = ['property-increment', 'property-decrement'];

	/** List routes whose actions apply the owner scope themselves. */
	private const OWNER_SCOPED_LIST_ROUTES = ['collection-query', 'collection-fetch-index'];

	/**
	 * Write operations: their `{property}` routes write the named property,
	 * and their `{id}` routes remember the object's forced values.
	 */
	private const PROPERTY_WRITE_OPERATIONS = ['create', 'update', 'delete'];

	public function __construct(
		UserValidationService $userValidation,
		AccessControlService $accessControl,
		SessionInterface $session,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		OAuthActivityLogger $oauthActivityLogger,
		private OwnerPropertyResolver $ownerProperties,
		private ObjectFetcher $objectFetcher,
		private AuthFieldPolicy $policy,
		private ForcedValueContext $forcedValues,
	) {
		parent::__construct($userValidation, $accessControl, $session, $jsonRenderer, $twigRenderer, $responseFactory, $config, $operationDetector, $loggerFactory, $oauthActivityLogger);
	}

	/**
	 * Check if the user has permission to access the requested collection:
	 * the group check, then the protected-property check, then on an owned
	 * collection the owner check.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		$route = $this->routeOf($request);
		if (!$route instanceof RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		if (!$this->groupAllows($userId, $operation, $request, $route)) {
			return false;
		}

		if (!$this->propertyAllows($userId, $operation, $request, $route)) {
			return false;
		}

		if (!$this->ownerAllows($userId, $operation, $request, $route)) {
			return false;
		}

		if (!$this->cloneAllows($request, $route)) {
			return false;
		}

		return $this->importAllows($route);
	}

	/**
	 * Whether `object-clone` may run: refused when the body names a target
	 * collection other than the route's (only the route collection is
	 * access-checked), and on any collection with a protected, forced or
	 * owner rule, because the copy would carry values the actor could not
	 * write. True for every other route.
	 */
	private function cloneAllows(ServerRequestInterface $request, RouteInterface $route): bool
	{
		if ((string)$route->getName() !== 'object-clone') {
			return true;
		}

		$collection = (string)$route->getArgument('collection', '');
		if (self::cloneTargetsOtherCollection($request, $collection)) {
			return false;
		}

		return $this->policy->protectedFieldsFor($collection) === [];
	}

	/**
	 * Whether a clone request's body names a target `collection` that is not
	 * identical to the route collection (any non-null value, of any type). ObjectCloneAction copies into the body's
	 * collection when one is given. Shared with DualAuthMiddleware's public
	 * path.
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
	 * Whether a collection import (`import-csv`, `import-json`,
	 * `import-deck-csv`, `import-deck-json`, `import-factory`) may run:
	 * imports upsert whole objects and no field policy runs on them, so they
	 * are refused on any collection with a protected, forced or owner rule.
	 * True for every other route.
	 */
	private function importAllows(RouteInterface $route): bool
	{
		if (!str_starts_with((string)$route->getName(), 'import-')) {
			return true;
		}

		return $this->policy->protectedFieldsFor((string)$route->getArgument('collection', '')) === [];
	}

	/**
	 * The field-policy decision for a write route that names a `{property}`.
	 * Only property-update/-patch apply the policy in their action; every other
	 * property route (delete, deck items, files, folders, meta, counters,
	 * uploads) writes the property with no check of its own, so the refusal
	 * lives here, once, for all of them. True for every route without a
	 * property and for reads.
	 */
	private function propertyAllows(string $userId, string $operation, ServerRequestInterface $request, RouteInterface $route): bool
	{
		if (!in_array($operation, self::PROPERTY_WRITE_OPERATIONS, true)) {
			return true;
		}

		$collection = (string)$route->getArgument('collection', '');
		$property   = (string)$route->getArgument('property', '');
		if ($collection === '' || $property === '') {
			return true;
		}

		return $this->policy->canWriteProperty($userId, $collection, $property, $this->actorCollectionFor($request));
	}

	/**
	 * The auth collection the actor belongs to: from the token's `sub` claim
	 * for a non-admin bearer (no session), otherwise the raw session value.
	 */
	private function actorCollectionFor(ServerRequestInterface $request): string
	{
		if ($request->getAttribute('accessAuthority') instanceof UserAuthority) {
			return OAuthUserRef::parse((string)$request->getAttribute('oauth_user_id', ''), (string)$this->config->auth['collection'])->collection;
		}

		return (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');
	}

	/**
	 * The access-group decision, exactly as before owned collections existed.
	 */
	private function groupAllows(string $userId, string $operation, ServerRequestInterface $request, RouteInterface $route): bool
	{
		$collection = $route->getArgument('collection');
		$objectId   = $route->getArgument('id');

		// OAuth Bearer callers: there is no PHP session to derive groups from,
		// so use the UserAuthority resolved from the token by BaseAccessMiddleware.
		// This MUST run before the self-profile carve-out below: the carve-out
		// gates on session AUTH_COLLECTION, not on auth method, so a Bearer
		// request that ALSO carries an active session cookie (browser-originated
		// token use) could otherwise hit the carve-out and update the auth
		// record — bypassing the group check on exactly the record that holds
		// `groups`. Returning here unconditionally for any accessAuthority-
		// bearing (i.e. Bearer) request closes that off.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $collection
				? $authority->canCollection($operation, $collection)
				: $authority->canCollectionsOperation($operation);
		}

		// Allow users to update their own profile (self-profile update).
		// Users should always be able to update their own record in their auth
		// collection. Only reachable for session callers — Bearer requests
		// always return above via the accessAuthority branch.
		//
		// AUTH_COLLECTION is empty for anyone who logged in at the plain
		// `/admin/login` route, which has no `{collection}` argument — i.e.
		// almost everyone (AuthLoginSubmitAction: `$args['collection'] ?? ''`).
		// An empty value means "the default auth collection" everywhere else in
		// the codebase (see UserValidationService::validateUserById), so it must
		// be resolved before comparing. Comparing the raw session value made
		// this carve-out dead for every default-login user: their own profile
		// save fell through to the group check and 403'd. Super-admins never saw
		// it — they bypass in BaseAccessMiddleware before reaching here.
		if ($operation === 'update' && $collection && $objectId) {
			if ($collection === $this->actorAuthCollection() && $objectId === $userId) {
				return true;
			}
		}

		// Check access permissions
		if ($collection) {
			// Specific collection - check access to that collection
			return $this->accessControl->canAccessCollection($userId, $collection, $operation);
		}

		// No specific collection (e.g., GET /collections, POST /collections) - check general collection operation permission
		return $this->accessControl->canAccessCollectionsOperation($userId, $operation);
	}

	/**
	 * The owner decision for a non-super-admin on an owned collection. True
	 * for every route on an ordinary collection.
	 */
	private function ownerAllows(string $userId, string $operation, ServerRequestInterface $request, RouteInterface $route): bool
	{
		$collection = (string)$route->getArgument('collection', '');
		if ($collection === '') {
			return true;
		}

		$ownerProperty = $this->ownerProperties->resolve($collection);
		if ($ownerProperty === '') {
			return true;
		}

		// The collection's settings could not be read, so nothing on it can be scoped: deny every route for a scoped actor.
		if ($ownerProperty === OwnerPropertyResolver::UNRESOLVED) {
			return false;
		}

		// An empty actor id (a bearer subject that parsed to nothing) must never
		// match an object whose owner is empty, nor receive a list scope.
		if ($userId === '') {
			return false;
		}

		$routeName = (string)$route->getName();
		if (in_array($routeName, self::OWNER_DENIED_ROUTES, true)) {
			return false;
		}

		$objectId = (string)$route->getArgument('id', '');
		if ($objectId === '') {
			// Allow-list: only the routes that are safe without an object to compare.
			return in_array($routeName, self::OWNER_ALLOWED_IDLESS_ROUTES, true);
		}

		if ($routeName === 'admin-collection' || $routeName === 'admin-collection-post') {
			// Admin pseudo-pages (-export, -import, -factory, -sitemap, -feeds)
			// work across every object; -export lists every object id.
			if (str_starts_with($objectId, '-')) {
				return false;
			}

			// Admin-side clone: POST .../add with a `duplicate` field copies
			// another object. Same condition AdminCollectionAction acts on, so
			// any string value (even "0") is refused for a scoped actor.
			$body = (array)$request->getParsedBody();
			if ($routeName === 'admin-collection-post' && isset($body['duplicate']) && is_string($body['duplicate'])) {
				return false;
			}
		}

		if (!$this->objectFetcher->existsObject($collection, $objectId)) {
			// A missing object passes only for a read, so the action returns its
			// usual 404 (this tells a scoped actor whether an id exists: 404
			// here, 403 for someone else's object; the spec accepts that, since
			// ids are slugs and the content stays unreadable). A create, update
			// or delete is denied: the property savers (file, image, gallery,
			// depot) create a missing object through ObjectSaver, outside the
			// field policy, so it would carry no owner stamp and no forced
			// values. Members create through `object-save`. The admin add form
			// (`admin-collection-post` on the `add` keyword) only renders a page.
			return $operation === 'read'
				|| ($routeName === 'admin-collection-post' && $objectId === 'add');
		}

		$object = $this->objectFetcher->fetchObject($collection, $objectId)->toArray();
		$owner  = $object[$ownerProperty] ?? '';

		return is_scalar($owner) && (string)$owner === $userId;
	}

	/**
	 * Hand the list actions the scope to apply, and remember the forced values
	 * for an allowed write. Only the two list routes get the scope; everything
	 * else passes untouched.
	 */
	protected function scopeRequest(ServerRequestInterface $request, string $userId, string $operation): ServerRequestInterface
	{
		$route = $this->routeOf($request);
		if (!$route instanceof RouteInterface) {
			return $request;
		}

		$scoped = $this->withOwnerScope($request, $route, $userId);
		$this->rememberForcedValues($request, $route, $userId, $operation);

		return $scoped;
	}

	/**
	 * The request with an `ownerScope` attribute on the two list routes of an
	 * owned collection; unchanged otherwise.
	 */
	private function withOwnerScope(ServerRequestInterface $request, RouteInterface $route, string $userId): ServerRequestInterface
	{
		if (!in_array((string)$route->getName(), self::OWNER_SCOPED_LIST_ROUTES, true)) {
			return $request;
		}

		$ownerProperty = $this->ownerProperties->resolve((string)$route->getArgument('collection', ''));
		// No scope for an unowned collection, nor for an unresolved one (the
		// request was denied before reaching here; kept explicit).
		if ($ownerProperty === '' || $ownerProperty === OwnerPropertyResolver::UNRESOLVED || $userId === '') {
			return $request;
		}

		return $request->withAttribute(self::OWNER_SCOPE_ATTRIBUTE, new OwnerScope($ownerProperty, $userId));
	}

	/**
	 * Remember what the field policy forces on the object an allowed
	 * non-admin write names, for ObjectUpdater::updateObject() to apply on
	 * every save in this request. Enforcing an empty write on an existing
	 * object yields exactly the forced values. Not wrapped in a catch: a
	 * failure here must not let the write through without them.
	 */
	private function rememberForcedValues(ServerRequestInterface $request, RouteInterface $route, string $userId, string $operation): void
	{
		if (!in_array($operation, self::PROPERTY_WRITE_OPERATIONS, true)) {
			return;
		}

		if (in_array((string)$route->getName(), self::COUNTER_ROUTES, true)) {
			return;
		}

		$collection = (string)$route->getArgument('collection', '');
		$objectId   = (string)$route->getArgument('id', '');
		if ($collection === '' || $objectId === '') {
			return;
		}

		$this->forcedValues->remember(
			$collection,
			$objectId,
			$this->policy->enforce($userId, $collection, $objectId, [], $this->actorCollectionFor($request)),
		);
	}

	/**
	 * Which check refused, in checkPermission()'s order: `group` when the
	 * group did not grant the operation, `protected` when the field policy
	 * refused the named property, `owner` when the owner check refused, and
	 * `protected` for a clone or import refused by the clone or import check
	 * (a protected collection, or a clone into another collection). Re-runs the
	 * decisions only on the denial path.
	 *
	 * @return array<string,mixed>
	 */
	protected function denialContext(ServerRequestInterface $request, string $userId, string $operation): array
	{
		$route = $this->routeOf($request);
		if (!$route instanceof RouteInterface) {
			return [];
		}

		if (!$this->groupAllows($userId, $operation, $request, $route)) {
			return ['reason' => 'group'];
		}

		if (!$this->propertyAllows($userId, $operation, $request, $route)) {
			return ['reason' => 'protected'];
		}

		return ['reason' => $this->ownerAllows($userId, $operation, $request, $route) ? 'protected' : 'owner'];
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

	/**
	 * The auth collection the session user belongs to, with an empty session
	 * value resolved to the configured default.
	 */
	private function actorAuthCollection(): string
	{
		$collection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');

		return $collection !== '' ? $collection : (string)$this->config->auth['collection'];
	}
}

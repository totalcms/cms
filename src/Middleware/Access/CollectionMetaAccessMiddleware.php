<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Collection Meta Access Middleware.
 *
 * Enforces access group permissions for collection metadata operations.
 * Handles routes for collection CRUD (not object CRUD).
 *
 * Registration settings (`publicRegistration`, `registrationGroups`) are
 * reserved to super admins and API keys, both of which bypass this class
 * before checkPermission() runs. Without that, any group holding a
 * `collectionsMeta` grant could open registration on an auth collection,
 * hand registrants a group of its choosing, then sign up anonymously and
 * hold that group: a privilege escalation from collection settings to
 * whatever the group grants.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") the base already takes
 * ten collaborators; the eleventh reads the stored registration settings.
 */
readonly class CollectionMetaAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection metadata';

	/** Collection settings only super admins and API keys may change. */
	private const REGISTRATION_KEYS = ['publicRegistration', 'registrationGroups'];

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
		private CollectionFetcher $collectionFetcher,
	) {
		parent::__construct($actorResolver, $actors, $jsonRenderer, $twigRenderer, $responseFactory, $config, $operationDetector, $loggerFactory, $denials);
	}

	/**
	 * Check if the user has permission to access the requested collection metadata.
	 * Everyone reaching here is a non-super-admin, so a write that changes the
	 * registration settings is refused whatever the group grants.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		if ($this->changesRegistration($operation, $request)) {
			return false;
		}

		// Get collection name from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$collection = $route->getArgument('collection');

		// A specific collection's metadata, or collections in general (GET /collections, POST /collections).
		return $collection
			? $actor->authority()->canCollectionMeta($operation, $collection)
			: $actor->authority()->canCollectionsMetaOperation($operation);
	}

	/** @return array<string,mixed> */
	protected function denialContext(ServerRequestInterface $request, Actor $actor, string $operation): array
	{
		return $this->changesRegistration($operation, $request) ? ['reason' => 'protected'] : [];
	}

	/**
	 * Whether a create or update would change a registration setting, read
	 * through the same normalisers CollectionSaver stores with. A key
	 * submitted with the value already stored is not a change: the admin
	 * collection form posts every field, hidden ones included, so refusing
	 * on the key alone would stop a grantee saving any collection at all.
	 * A new collection compares against the defaults (off, no groups).
	 */
	private function changesRegistration(string $operation, ServerRequestInterface $request): bool
	{
		if (!in_array($operation, ['create', 'update'], true)) {
			return false;
		}

		$body = $request->getParsedBody();
		if (!is_array($body) || array_intersect(self::REGISTRATION_KEYS, array_keys($body)) === []) {
			return false;
		}

		// A new collection, or one that cannot be read, compares against the defaults.
		$stored = ($operation === 'update' ? $this->storedCollection($request) : null) ?? new CollectionData();

		if (array_key_exists('publicRegistration', $body)
			&& CollectionData::normalizePublicRegistration($body['publicRegistration']) !== $stored->publicRegistration) {
			return true;
		}

		return array_key_exists('registrationGroups', $body)
			&& $this->sorted(CollectionData::normalizeRegistrationGroups($body['registrationGroups'])) !== $this->sorted(CollectionData::normalizeRegistrationGroups($stored->registrationGroups));
	}

	/** The record being updated, or null when it cannot be read. */
	private function storedCollection(ServerRequestInterface $request): ?CollectionData
	{
		$route      = $request->getAttribute(RouteContext::ROUTE);
		$collection = $route instanceof RouteInterface ? (string)$route->getArgument('collection', '') : '';
		if ($collection === '') {
			return null;
		}

		try {
			return $this->collectionFetcher->fetchCollection($collection);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Order does not matter to a group grant.
	 *
	 * @param list<string> $groups
	 *
	 * @return list<string>
	 */
	private function sorted(array $groups): array
	{
		sort($groups);

		return $groups;
	}
}

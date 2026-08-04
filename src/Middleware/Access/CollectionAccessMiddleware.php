<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Collection Access Middleware.
 *
 * Enforces access group permissions for collection operations.
 */
readonly class CollectionAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection';

	/**
	 * Check if the user has permission to access the requested collection.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		// Get collection name from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$collection = $route->getArgument('collection');
		$objectId   = $route->getArgument('id');

		// Allow users to update their own profile (self-profile update).
		// Users should always be able to update their own record in their auth
		// collection. Bearer requests have no session; OAuthUserRef::parse()
		// resolved the token's own auth collection into $userId's identity,
		// so this self-profile carve-out only applies to session callers.
		if ($operation === 'update' && $collection && $objectId) {
			$authCollection = $this->session->get(SessionKeys::AUTH_COLLECTION);
			if ($collection === $authCollection && $objectId === $userId) {
				return true;
			}
		}

		// OAuth Bearer callers: there is no PHP session to derive groups from,
		// so use the UserAuthority resolved from the token by BaseAccessMiddleware.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $collection
				? $authority->canCollection($operation, $collection)
				: $authority->canCollectionsOperation($operation);
		}

		// Check access permissions
		if ($collection) {
			// Specific collection - check access to that collection
			return $this->accessControl->canAccessCollection($userId, $collection, $operation);
		}

		// No specific collection (e.g., GET /collections, POST /collections) - check general collection operation permission
		return $this->accessControl->canAccessCollectionsOperation($userId, $operation);
	}
}

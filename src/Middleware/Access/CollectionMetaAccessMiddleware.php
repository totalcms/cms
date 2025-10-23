<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Collection Metadata Access Middleware.
 *
 * Enforces access group permissions for collection metadata operations.
 * Handles routes for collection CRUD (not object CRUD).
 */
readonly class CollectionMetaAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection metadata';

	/**
	 * Check if the user has permission to access the requested collection metadata.
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

		// Check access permissions
		if ($collection) {
			// Specific collection - check access to that collection's metadata
			return $this->accessControl->canAccessCollectionMeta($userId, $collection, $operation);
		}

		// No specific collection (e.g., GET /collections, POST /collections) - check general collection meta operation permission
		return $this->accessControl->canAccessCollectionsMetaOperation($userId, $operation);
	}
}

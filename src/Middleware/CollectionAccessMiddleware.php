<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

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

		// Check access permissions
		if ($collection) {
			// Specific collection - check access to that collection
			return $this->accessControl->canAccessCollection($userId, $collection, $operation);
		}

		// No specific collection (e.g., GET /collections, POST /collections) - check general collection operation permission
		return $this->accessControl->canAccessCollectionsOperation($userId, $operation);
	}
}

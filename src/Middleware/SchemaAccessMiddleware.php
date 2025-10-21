<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Schema Access Middleware.
 *
 * Enforces access group permissions for schema operations.
 */
readonly class SchemaAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'schema';

	/**
	 * Check if the user has permission to access the requested schema.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		// Get schema ID from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$schema = $route->getArgument('schema');

		// Check access permissions
		if ($schema) {
			// Specific schema - check access to that schema
			return $this->accessControl->canAccessSchema($userId, $schema, $operation);
		}

		// No specific schema (e.g., GET /schemas, POST /schemas) - check general schema operation permission
		return $this->accessControl->canAccessSchemasOperation($userId, $operation);
	}
}

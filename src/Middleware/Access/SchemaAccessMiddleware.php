<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use Slim\Interfaces\RouteInterface;
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
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		// Get schema ID from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$schema = $route->getArgument('schema');

		// A specific schema, or schemas in general (GET /schemas, POST /schemas).
		return $schema
			? $actor->authority()->canSchema($operation, $schema)
			: $actor->authority()->canSchemasOperation($operation);
	}
}

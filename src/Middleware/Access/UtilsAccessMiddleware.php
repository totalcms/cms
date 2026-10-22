<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Utils Access Middleware.
 *
 * Enforces access group permissions for utils operations.
 */
readonly class UtilsAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'utils';

	/**
	 * Check if the user has permission to access the requested utils page.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		// Get page from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$page = $route->getArgument('page');

		// A specific page (boolean, no operations), or any utils page at all (GET /utils).
		return $page
			? $actor->authority()->canUtil($page)
			: $actor->authority()->canAnyUtil();
	}
}

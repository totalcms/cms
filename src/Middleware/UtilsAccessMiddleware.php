<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;
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
	protected function checkPermission(string $userId, string $method, ServerRequestInterface $request): bool
	{
		// Get page from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$page = $route->getArgument('page');

		// Check access permissions
		if ($page) {
			// Specific page - check access to that page
			return $this->accessControl->canAccessUtils($userId, $page, $method);
		}

		// No specific page (e.g., GET /utils) - check general utils method permission
		return $this->accessControl->canAccessUtilsMethod($userId, $method);
	}
}

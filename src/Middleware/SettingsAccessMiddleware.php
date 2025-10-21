<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Settings Access Middleware.
 *
 * Enforces access group permissions for settings operations.
 */
readonly class SettingsAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'settings';

	/**
	 * Check if the user has permission to access the requested settings section.
	 */
	protected function checkPermission(string $userId, string $method, ServerRequestInterface $request): bool
	{
		// Get section from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$section = $route->getArgument('section');

		// Check access permissions
		if ($section) {
			// Specific section - check access to that section
			return $this->accessControl->canAccessSettings($userId, $section, $method);
		}

		// No specific section (e.g., GET /settings) - check general settings method permission
		return $this->accessControl->canAccessSettingsMethod($userId, $method);
	}
}

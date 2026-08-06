<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\UserAuthority;

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
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		// Get page from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$page = $route->getArgument('page');

		// OAuth Bearer callers: no PHP session to derive groups from — use the
		// UserAuthority resolved from the token by BaseAccessMiddleware. Added
		// as part of the Task 8 fix round (cache access review) — this class
		// previously had no Bearer branch at all, meaning a Bearer request
		// would fall through to the session-coupled accessControl calls below
		// and read from an absent session.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $page
				? $authority->canUtil($page)
				: $authority->canAnyUtil();
		}

		// Check access permissions
		if ($page) {
			// Specific page - check access to that page (boolean, no operations)
			return $this->accessControl->canAccessUtils($userId, $page);
		}

		// No specific page (e.g., GET /utils) - check if user has ANY utils access
		return $this->accessControl->canAccessAnyUtils($userId);
	}
}

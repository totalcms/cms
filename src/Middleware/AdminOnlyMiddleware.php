<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin Only Middleware.
 *
 * Ensures that only super admin users can access protected routes.
 * Use this for sensitive administrative functions like API keys, access groups, etc.
 *
 * Note: Super admins are checked in BaseAccessMiddleware and bypass this check.
 * If we reach checkPermission(), the user is NOT an admin and should be denied.
 */
readonly class AdminOnlyMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'admin resource';

	/**
	 * Check if the user has permission.
	 * Since admins bypass this in BaseAccessMiddleware, reaching here means user is NOT an admin.
	 * Method and request parameters are not used for admin-only checks.
	 */
	protected function checkPermission(string $userId, string $method, ServerRequestInterface $request): bool
	{
		// If we reach here, user is not a super admin (admins bypass in base class)
		return false;
	}

	/**
	 * Get error message based on environment.
	 */
	protected function getErrorMessage(): string
	{
		$isDev = $this->config->env === 'dev';

		return $isDev
			? 'Access denied: Only super admin users can access this resource'
			: 'Access denied';
	}
}

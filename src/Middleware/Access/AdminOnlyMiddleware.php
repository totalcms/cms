<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

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

	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		// Trusted actors (super admins) pass in BaseAccessMiddleware; reaching
		// here means the actor is not one.
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

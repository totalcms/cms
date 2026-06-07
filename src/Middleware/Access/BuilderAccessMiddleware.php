<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Builder Access Middleware.
 *
 * Enforces access group permissions for Site Builder access.
 */
readonly class BuilderAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'builder';

	/**
	 * Check if the user has permission to access the Site Builder.
	 * Builder doesn't have operation-based permissions, just boolean access.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessBuilder($userId);
	}
}

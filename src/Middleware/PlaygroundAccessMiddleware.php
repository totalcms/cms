<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Playground Access Middleware.
 *
 * Enforces access group permissions for playground access.
 */
readonly class PlaygroundAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'playground';

	/**
	 * Check if the user has permission to access playground.
	 * Playground doesn't have operation-based permissions, just boolean access.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessPlayground($userId);
	}
}

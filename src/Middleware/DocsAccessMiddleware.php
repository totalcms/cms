<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Docs Access Middleware.
 *
 * Enforces access group permissions for documentation access.
 */
readonly class DocsAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'docs';

	/**
	 * Check if the user has permission to access documentation.
	 * Docs don't have method-based permissions, just boolean access.
	 */
	protected function checkPermission(string $userId, string $method, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessDocs($userId);
	}
}

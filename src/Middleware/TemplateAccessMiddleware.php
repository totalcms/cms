<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Template Access Middleware.
 *
 * Enforces access group permissions for template operations.
 */
readonly class TemplateAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'template';

	/**
	 * Check if the user has permission to access templates.
	 * Templates don't have individual access, just method-based permissions.
	 */
	protected function checkPermission(string $userId, string $method, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessTemplatesMethod($userId, $method);
	}
}

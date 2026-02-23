<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Data Views Access Middleware.
 *
 * Enforces access group permissions for data views access.
 */
readonly class DataViewsAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'dataviews';

	/**
	 * Check if the user has permission to access data views.
	 * Data views doesn't have operation-based permissions, just boolean access.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		return $this->accessControl->canAccessDataViews($userId);
	}
}

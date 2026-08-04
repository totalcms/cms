<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\UserAuthority;

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
		// OAuth Bearer callers: no PHP session to derive groups from — use the
		// UserAuthority resolved from the token by BaseAccessMiddleware.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $authority->canDataViews();
		}

		return $this->accessControl->canAccessDataViews($userId);
	}
}

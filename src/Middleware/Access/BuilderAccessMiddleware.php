<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\UserAuthority;

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
		// OAuth Bearer callers: no PHP session to derive groups from — use the
		// UserAuthority resolved from the token by BaseAccessMiddleware.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $authority->canBuilder();
		}

		return $this->accessControl->canAccessBuilder($userId);
	}
}

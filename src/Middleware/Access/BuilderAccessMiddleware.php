<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

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
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return $actor->authority()->canBuilder();
	}
}

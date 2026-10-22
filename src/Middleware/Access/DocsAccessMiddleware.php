<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

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
	 * Docs don't have operation-based permissions, just boolean access.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return $actor->authority()->canDocs();
	}
}

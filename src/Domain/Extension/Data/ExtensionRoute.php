<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Represents a matched extension route.
 *
 * $permission is meaningful for ADMIN routes: 'admin' (super admins only)
 * or 'any' (any logged-in dashboard user). The admin-route matcher
 * normalizes unknown values to 'admin' (fail closed). API/public route
 * matches leave the default.
 */
final readonly class ExtensionRoute
{
	public function __construct(
		public mixed $handler,
		public bool $public = false,
		public string $permission = 'any',
	) {
	}
}

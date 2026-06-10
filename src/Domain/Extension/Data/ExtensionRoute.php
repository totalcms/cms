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
 *
 * $params holds any values captured from Slim-style {placeholder} segments
 * in the registered path (e.g. `/s/{id}` matched against `/s/abc` yields
 * `['id' => 'abc']`). The dispatching action merges these into the handler's
 * $args. Empty for static routes.
 */
final readonly class ExtensionRoute
{
	/**
	 * @param array<string,string> $params
	 */
	public function __construct(
		public mixed $handler,
		public bool $public = false,
		public string $permission = 'any',
		public array $params = [],
	) {
	}
}

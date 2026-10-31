<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;

/**
 * Import Access Middleware.
 *
 * Enforces the `utils` / 'import' access-group permission on the whole-site
 * importers (Total CMS 1, Alloy, WordPress, RSS). Those routes carried only
 * AuthMiddleware, so any admin login could run them whatever its access group:
 * a security scan (2026-10-30) reached all seven as a user in the read-only
 * `viewer` group. The admin import PAGES were already gated by the same
 * permission through {@see UtilsAccessMiddleware}; the API they post to was
 * not, the same gap {@see CacheAccessMiddleware} closed for the cache routes.
 *
 * The per-collection importers (CSV, JSON, deck, factory) keep
 * CollectionAccessMiddleware: they name their target collection in the route.
 */
readonly class ImportAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'import';

	/**
	 * Import access is a single boolean permission (the `import` util), so
	 * $operation is unused.
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return $actor->authority()->canUtil('import');
	}

	/**
	 * These routes are not in OperationDetector's route-name lists, so the
	 * default would deny on detection failure before checkPermission() runs.
	 * Mirrors CacheAccessMiddleware's override.
	 */
	protected function detectOperation(ServerRequestInterface $request): ?string
	{
		return strtoupper($request->getMethod()) === 'GET' ? 'read' : 'create';
	}
}

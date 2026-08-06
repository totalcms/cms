<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\UserAuthority;

/**
 * Cache Access Middleware.
 *
 * Enforces the `utils` / 'cache' access-group permission on the `/api/cache`
 * routes (delete cache, delete images/watermarks, devmode toggle). Before
 * this class existed, `/api/cache` was mounted with ONLY `AuthMiddleware` —
 * any authenticated caller (session OR Bearer, admin or not) could flush
 * caches and toggle devmode. A security review (2026-08) proved this live: a
 * non-admin OAuth Bearer token could `DELETE /api/cache`,
 * `POST /api/cache/devmode`, and `DELETE /api/cache/watermarks`, all 200.
 * The same gap existed for session-authenticated non-admin dashboard users —
 * the admin-UI cache-manager PAGE was correctly gated by
 * {@see UtilsAccessMiddleware} on `/admin/utils/cache-manager`, but the
 * page's own form posts straight to this unguarded API route, so the UI gate
 * was cosmetic.
 *
 * This is deliberately its OWN class rather than reusing
 * {@see UtilsAccessMiddleware} unmodified: that class resolves the target
 * util from a `{page}` route argument (`/admin/utils/{page}`), which these
 * routes don't have — they're fixed paths (`/cache`, `/cache/devmode`, …).
 * Falling through to UtilsAccessMiddleware's "no page" branch would check
 * "has ANY utils access at all" rather than "has cache access specifically",
 * over-granting to a caller with, say, only `jumpstart` access. These routes
 * also aren't in {@see \TotalCMS\Domain\Auth\Service\OperationDetector}'s
 * route-name lists (they map to no CRUD-shaped resource), so the CRUD
 * operation is irrelevant to the permission check — hence the fixed
 * `canUtil('cache')` / `canAccessUtils($userId, 'cache')` short-circuit
 * instead of inventing OperationDetector entries.
 */
readonly class CacheAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'cache';

	/**
	 * Cache access is a single boolean permission (the `cache` util) — no
	 * per-target/per-operation dimension, so $operation is unused.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		// OAuth Bearer callers: no PHP session to derive groups from — use the
		// UserAuthority resolved from the token by BaseAccessMiddleware.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $authority->canUtil('cache');
		}

		return $this->accessControl->canAccessUtils($userId, 'cache');
	}

	/**
	 * These routes aren't in OperationDetector's route-name lists (they're
	 * not a CRUD-shaped resource), so the default would deny on detection
	 * failure. checkPermission() above ignores the operation entirely — this
	 * override only keeps the base class from denying before reaching it.
	 * Mirrors ExtensionAdminAccessMiddleware's identical override.
	 */
	protected function detectOperation(ServerRequestInterface $request): ?string
	{
		return match (strtoupper($request->getMethod())) {
			'POST'         => 'create',
			'PUT', 'PATCH' => 'update',
			'DELETE'       => 'delete',
			default        => 'read',
		};
	}
}

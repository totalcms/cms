<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Resolves the URL prefix the front controller is mounted at by cross-checking
 * SCRIPT_NAME against the actual request path.
 *
 * This is the single source of truth shared by:
 *   - BasePathMiddleware  (tells Slim what prefix to strip when routing)
 *   - config/defaults.php (sets `config->api`, the prefix used to build URLs)
 *
 * Both MUST agree: if routing strips `/tcms` but link-building emits
 * `/tcms/public`, generated API URLs 404. Keeping the computation here
 * guarantees they can never drift apart.
 *
 * Two layouts ship with T3 and they need opposite stripping depths:
 *
 *   1. Classic "public dir hidden" layout (Stacks plugin, Symfony-style),
 *      where a docroot rewrite serves public/ at its parent so URLs never
 *      contain `/public` — but SCRIPT_NAME still points at the real script:
 *      SCRIPT_NAME = `/rw_common/plugins/stacks/tcms/public/index.php`
 *      URL         = `/rw_common/plugins/stacks/tcms/admin`
 *      basePath    = `/rw_common/plugins/stacks/tcms`  (strip `/public/index.php`)
 *
 *   2. Composer subpath layout (front controller AT the URL mount):
 *      SCRIPT_NAME = `/tcms/index.php`
 *      URL         = `/tcms/admin`
 *      basePath    = `/tcms`  (strip just `/index.php`)
 *
 * SCRIPT_NAME alone can't tell us which we're in, so we cross-check against
 * the request path: try the script's own directory first, fall back to its
 * parent if the URL clearly mounts one level higher (the canonical public/
 * pattern). Must NOT be derived from filesystem paths — the project root is
 * not necessarily a subdirectory of the document root.
 */
final class BasePath
{
	public static function resolve(string $scriptName, string $requestPath): string
	{
		if ($scriptName === '') {
			return '';
		}

		$scriptName = str_replace('\\', '/', $scriptName);

		// First candidate: the directory the script physically lives in.
		// Matches the Composer subpath layout and the root install.
		$scriptDir = self::normalizeDir(dirname($scriptName));
		if ($scriptDir !== '' && self::uriMountedAt($requestPath, $scriptDir)) {
			return $scriptDir;
		}

		// Second candidate: one level above the script's directory. Covers
		// the classic Symfony/Laravel/Stacks pattern where `public/` is
		// hidden by a docroot rewrite, so URLs come in without it.
		$parentDir = self::normalizeDir(dirname($scriptDir));
		if ($parentDir !== '' && self::uriMountedAt($requestPath, $parentDir)) {
			return $parentDir;
		}

		return '';
	}

	private static function normalizeDir(string $dir): string
	{
		$dir = str_replace('\\', '/', $dir);

		// dirname returns '/', '.', or '\\' at the top — all map to "no base path".
		if (in_array($dir, ['/', '.', '\\'], true)) {
			return '';
		}

		return $dir;
	}

	private static function uriMountedAt(string $requestPath, string $prefix): bool
	{
		return $requestPath === $prefix || str_starts_with($requestPath, $prefix . '/');
	}
}

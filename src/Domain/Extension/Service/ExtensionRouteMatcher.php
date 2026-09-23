<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

/**
 * Matches a request against an extension's registered routes with
 * Slim-style `{placeholder}` segments. An exact static match wins over a
 * placeholder pattern (FastRoute precedence), so a literal `/embed/list` is
 * never shadowed by `/embed/{id}` regardless of registration order.
 */
final class ExtensionRouteMatcher
{
	/**
	 * @param list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}> $routes
	 *
	 * @return array{route: array{method: string, path: string, handler: mixed, public: bool, permission: string|null}, params: array<string,string>}|null
	 */
	public static function resolve(array $routes, string $method, string $path): ?array
	{
		foreach ($routes as $route) {
			if ($route['method'] === $method && $route['path'] === $path) {
				return ['route' => $route, 'params' => []];
			}
		}

		foreach ($routes as $route) {
			if ($route['method'] !== $method || !str_contains($route['path'], '{')) {
				continue;
			}

			$params = self::matchPath($route['path'], $path);
			if ($params !== null) {
				return ['route' => $route, 'params' => $params];
			}
		}

		return null;
	}

	/**
	 * Match a path against a pattern with `{placeholder}` segments, mirroring
	 * Slim/FastRoute: `{id}` captures one non-slash segment, `{id:\d+}` adds a
	 * regex constraint, literal portions match verbatim. The captured params
	 * on a match, null otherwise.
	 *
	 * @return array<string,string>|null
	 */
	public static function matchPath(string $pattern, string $path): ?array
	{
		$regex  = '';
		$offset = 0;

		preg_match_all('/\{(\w+)(?::([^{}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

		foreach ($matches as $match) {
			$placeholder = $match[0][0];
			$position    = $match[0][1];
			$name        = $match[1][0];
			$constraint  = (isset($match[2]) && $match[2][1] !== -1) ? $match[2][0] : '[^/]+';

			$regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
			$regex .= '(?P<' . $name . '>' . $constraint . ')';
			$offset  = $position + strlen($placeholder);
		}

		$regex .= preg_quote(substr($pattern, $offset), '#');

		if (preg_match('#^' . $regex . '$#', $path, $captured) !== 1) {
			return null;
		}

		// Only the named captures; preg also returns numeric-indexed entries.
		$params = [];
		foreach ($captured as $key => $value) {
			if (is_string($key)) {
				$params[$key] = $value;
			}
		}

		return $params;
	}
}

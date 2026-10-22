<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Auth\Data\UserAuthority;

/**
 * A key's path list read as an access group, for surfaces that cannot use
 * the key's path prefixes directly. MCP is one: every call is a POST to
 * `/mcp`, so the prefixes say nothing about which collection a tool touches.
 *
 *   - `*` is unlimited: the key has no authority to consult;
 *   - `collections` grants every collection, `collections/{id}` that one
 *     (its objects and its settings);
 *   - `schemas` grants every schema, `schemas/{id}` that one;
 *   - `templates` grants the Site Builder;
 *   - `dataviews` grants every data view, `dataviews/{id}` (or the REST
 *     route `dataviews/{id}/data`) that one ({@see reachesView()});
 *   - any other path (`mcp`, `cron/...`) grants no content.
 *
 * The key's methods are not applied: over MCP the method is always POST, so
 * within the collections it covers a key may read and write. REST and XML-RPC
 * keep checking the key's own methods and prefixes.
 */
final readonly class ApiKeyGrants
{
	private const OPERATIONS = ['read', 'create', 'update', 'delete'];

	public static function isUnlimited(ApiKeyData $apiKey): bool
	{
		return in_array('*', self::paths($apiKey), true);
	}

	/**
	 * Whether the key's paths name data view $viewId.
	 */
	public static function reachesView(ApiKeyData $apiKey, string $viewId): bool
	{
		$paths = self::paths($apiKey);
		if (in_array('*', $paths, true) || in_array('dataviews', $paths, true)) {
			return true;
		}

		return in_array($viewId, self::targets($paths, 'dataviews')['allowed'], true);
	}

	/**
	 * The key's grants, or null for an unlimited key.
	 */
	public static function authority(ApiKeyData $apiKey): ?UserAuthority
	{
		if (self::isUnlimited($apiKey)) {
			return null;
		}

		$collections = self::targets(self::paths($apiKey), 'collections');
		$schemas     = self::targets(self::paths($apiKey), 'schemas');

		return new UserAuthority(isAdmin: false, groups: [new AccessGroupData([
			'id'          => 'apikey:' . $apiKey->id,
			'permissions' => [
				'collections'     => $collections,
				'collectionsMeta' => $collections,
				'schemas'         => $schemas,
				'builder'         => in_array('templates', self::paths($apiKey), true),
				'mailer'          => false,
				'playground'      => false,
				'dataviews'       => false,
				'docs'            => false,
				'inlineEdit'      => false,
				'utils'           => ['all' => false, 'allowed' => []],
				'settings'        => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		])]);
	}

	/**
	 * The grant section for `$section` (`collections`, `schemas`): all of them
	 * for the bare path, the named ones for `section/{id}`.
	 *
	 * @param list<string> $paths
	 *
	 * @return array{operations: list<string>, all: bool, allowed: list<string>}
	 */
	private static function targets(array $paths, string $section): array
	{
		$allowed = [];
		foreach ($paths as $path) {
			if ($path === $section) {
				return ['operations' => self::OPERATIONS, 'all' => true, 'allowed' => []];
			}
			if (str_starts_with($path, $section . '/')) {
				$allowed[] = explode('/', substr($path, strlen($section) + 1))[0];
			}
		}

		return ['operations' => $allowed === [] ? [] : self::OPERATIONS, 'all' => false, 'allowed' => array_values(array_unique($allowed))];
	}

	/**
	 * The key's paths, normalized as ApiKeyPermissionChecker matches them:
	 * lowercase, no surrounding slashes, no `api/` prefix.
	 *
	 * @return list<string>
	 */
	private static function paths(ApiKeyData $apiKey): array
	{
		$paths = [];
		foreach ((array)($apiKey->scopes['paths'] ?? []) as $path) {
			$path = strtolower(trim((string)$path, '/'));
			if ($path === 'api' || str_starts_with($path, 'api/')) {
				$path = substr($path, 4);
			}
			$paths[] = $path;
		}

		return $paths;
	}
}

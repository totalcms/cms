<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Auth\Data\UserAuthority;

/**
 * A key's paths and methods read as an access group, for surfaces that
 * cannot use them directly. MCP is one: every call is a POST to `/mcp`, so
 * neither the method nor the path says what a tool does or touches.
 *
 * Paths say what the key reaches:
 *   - `*` reaches everything;
 *   - `collections` every collection, `collections/{id}` that one (its
 *     objects and its settings);
 *   - `schemas` every schema, `schemas/{id}` that one;
 *   - `templates` the Site Builder;
 *   - `dataviews` every data view, `dataviews/{id}` (or the REST route
 *     `dataviews/{id}/data`) that one ({@see reachesView()});
 *   - any other path (`mcp`, `cron/...`) reaches no content.
 *
 * Methods say what it may do with content, as on REST: GET reads, POST
 * creates, PUT or PATCH updates, DELETE deletes. Structure — schemas and
 * collection settings — is read-only to a limited key whatever its methods,
 * as REST's structure routes take no API key at all. A key with `*` and every
 * operation is the operator's admin key ({@see isUnlimited()}).
 */
final readonly class ApiKeyGrants
{
	private const METHOD_OPERATIONS = ['GET' => 'read', 'POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'];

	/**
	 * Whether the key is an admin: every path and every operation.
	 */
	public static function isUnlimited(ApiKeyData $apiKey): bool
	{
		return in_array('*', self::paths($apiKey), true) && count(self::operations($apiKey)) === 4;
	}

	/**
	 * Whether the key may read data view $viewId.
	 */
	public static function reachesView(ApiKeyData $apiKey, string $viewId): bool
	{
		if (!in_array('read', self::operations($apiKey), true)) {
			return false;
		}

		$paths = self::paths($apiKey);
		if (in_array('*', $paths, true) || in_array('dataviews', $paths, true)) {
			return true;
		}

		return in_array($viewId, self::targets($paths, 'dataviews', ['read'])['allowed'], true);
	}

	/**
	 * The key's grants, or null for an unlimited key.
	 */
	public static function authority(ApiKeyData $apiKey): ?UserAuthority
	{
		if (self::isUnlimited($apiKey)) {
			return null;
		}

		$paths       = self::paths($apiKey);
		$operations  = self::operations($apiKey);
		$readOnly    = array_values(array_intersect($operations, ['read']));
		$everything  = in_array('*', $paths, true);

		return new UserAuthority(isAdmin: false, groups: [new AccessGroupData([
			'id'          => 'apikey:' . $apiKey->id,
			'permissions' => [
				'collections'     => self::targets($paths, 'collections', $operations),
				'collectionsMeta' => self::targets($paths, 'collections', $readOnly),
				'schemas'         => self::targets($paths, 'schemas', $readOnly),
				'builder'         => ($everything || in_array('templates', $paths, true)) && in_array('read', $operations, true),
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
	 * The grant section for `$section` (`collections`, `schemas`, `dataviews`):
	 * all of them for `*` or the bare path, the named ones for `section/{id}`.
	 *
	 * @param list<string> $paths
	 * @param list<string> $operations
	 *
	 * @return array{operations: list<string>, all: bool, allowed: list<string>}
	 */
	private static function targets(array $paths, string $section, array $operations): array
	{
		$allowed = [];
		foreach ($paths as $path) {
			if ($path === '*' || $path === $section) {
				return ['operations' => $operations, 'all' => true, 'allowed' => []];
			}
			if (str_starts_with($path, $section . '/')) {
				$allowed[] = explode('/', substr($path, strlen($section) + 1))[0];
			}
		}

		return ['operations' => $allowed === [] ? [] : $operations, 'all' => false, 'allowed' => array_values(array_unique($allowed))];
	}

	/**
	 * The operations the key's methods allow.
	 *
	 * @return list<string>
	 */
	private static function operations(ApiKeyData $apiKey): array
	{
		$operations = [];
		foreach ((array)($apiKey->scopes['methods'] ?? []) as $method) {
			$operation = self::METHOD_OPERATIONS[strtoupper((string)$method)] ?? null;
			if ($operation !== null) {
				$operations[$operation] = $operation;
			}
		}

		return array_values($operations);
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

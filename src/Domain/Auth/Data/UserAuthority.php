<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;

/**
 * Thin, memoizing wrapper around a resolved user's admin flag + access
 * groups. Every can*() check delegates to AccessGroupData — the single home
 * for per-group permission logic shared with AccessControlService — so
 * permission rules are never re-implemented here.
 *
 * Built via AccessControlService::authorityFor(), or UserAuthority::denied()
 * for a user that could not be resolved.
 */
final class UserAuthority
{
	/** @var array<string,bool> */
	private array $memo = [];

	/**
	 * @param list<AccessGroupData> $groups
	 */
	public function __construct(
		public readonly bool $isAdmin,
		private readonly array $groups,
	) {
	}

	/**
	 * Unknown/unresolvable user: denies everything.
	 */
	public static function denied(): self
	{
		return new self(isAdmin: false, groups: []);
	}

	public function canCollection(string $op, string $collection): bool
	{
		return $this->remember("collection:$op:$collection", function () use ($op, $collection): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if ($group->allowsCollection($op, $collection)) {
					return true;
				}
			}

			return false;
		});
	}

	public function canCollectionMeta(string $op, string $collection): bool
	{
		return $this->remember("collectionMeta:$op:$collection", function () use ($op, $collection): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if ($group->allowsCollectionMeta($op, $collection)) {
					return true;
				}
			}

			return false;
		});
	}

	public function canSchema(string $op, string $schema): bool
	{
		return $this->remember("schema:$op:$schema", function () use ($op, $schema): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if ($group->allowsSchema($op, $schema)) {
					return true;
				}
			}

			return false;
		});
	}

	public function canUtil(string $util): bool
	{
		return $this->remember("util:$util", function () use ($util): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if ($group->allowsUtil($util)) {
					return true;
				}
			}

			return false;
		});
	}

	/**
	 * Boolean "has ANY utils access at all" (no specific util), for routes
	 * without a page argument (e.g. `GET /admin/utils`). Mirrors
	 * AccessControlService::canAccessAnyUtils() / groupCanAccessAnyUtils().
	 */
	public function canAnyUtil(): bool
	{
		return $this->remember('anyUtil', function (): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				$permissions = $group->permissions['utils'] ?? [];
				$all         = $permissions['all'] ?? false;
				$allowed     = $permissions['allowed'] ?? [];

				if ($all || $allowed !== []) {
					return true;
				}
			}

			return false;
		});
	}

	/**
	 * Bulk variant of canCollection() for routes without a specific
	 * collection target (e.g. `GET /collections`, `POST /collections`).
	 * Mirrors AccessControlService::canAccessCollectionsOperation().
	 */
	public function canCollectionsOperation(string $op): bool
	{
		return $this->remember("collectionsOperation:$op", fn (): bool => $this->groupGrantsBulkOperation('collections', $op));
	}

	/**
	 * Bulk variant of canCollectionMeta() for routes without a specific
	 * collection target. Mirrors AccessControlService::canAccessCollectionsMetaOperation().
	 */
	public function canCollectionsMetaOperation(string $op): bool
	{
		return $this->remember("collectionsMetaOperation:$op", fn (): bool => $this->groupGrantsBulkOperation('collectionsMeta', $op));
	}

	/**
	 * Bulk variant of canSchema() for routes without a specific schema
	 * target. Mirrors AccessControlService::canAccessSchemasOperation().
	 */
	public function canSchemasOperation(string $op): bool
	{
		return $this->remember("schemasOperation:$op", fn (): bool => $this->groupGrantsBulkOperation('schemas', $op));
	}

	/**
	 * Boolean access to the Site Builder. Mirrors
	 * AccessControlService::canAccessBuilder() (including the `templates`
	 * fallback for groups saved before the dedicated `builder` permission
	 * existed).
	 */
	public function canBuilder(): bool
	{
		return $this->remember('builder', function (): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if (($group->permissions['builder'] ?? ($group->permissions['templates'] ?? false)) === true) {
					return true;
				}
			}

			return false;
		});
	}

	/**
	 * Boolean access to data views. Mirrors AccessControlService::canAccessDataViews().
	 */
	public function canDataViews(): bool
	{
		return $this->remember('dataviews', fn (): bool => $this->groupGrantsBooleanPermission('dataviews'));
	}

	/**
	 * Boolean access to the Twig playground. Mirrors AccessControlService::canAccessPlayground().
	 */
	public function canPlayground(): bool
	{
		return $this->remember('playground', fn (): bool => $this->groupGrantsBooleanPermission('playground'));
	}

	/**
	 * Shared logic for the "no specific target" bulk permission blocks
	 * (collections / collectionsMeta / schemas), each shaped as
	 * {all, allowed, operations}. Grants when any group has SOME access
	 * (all=true or a non-empty allowed list) AND lists $op in operations.
	 */
	private function groupGrantsBulkOperation(string $permissionKey, string $op): bool
	{
		if ($this->isAdmin) {
			return true;
		}

		foreach ($this->groups as $group) {
			$permissions = $group->permissions[$permissionKey] ?? [];
			$all         = $permissions['all'] ?? false;
			$allowed     = $permissions['allowed'] ?? [];

			if (!$all && $allowed === []) {
				continue;
			}

			$operations = $permissions['operations'] ?? [];
			if (in_array($op, $operations, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shared logic for simple boolean feature-toggle permissions
	 * (dataviews, playground, mailer, docs, ...).
	 */
	private function groupGrantsBooleanPermission(string $permissionKey): bool
	{
		if ($this->isAdmin) {
			return true;
		}

		foreach ($this->groups as $group) {
			if (($group->permissions[$permissionKey] ?? false) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when this authority has any grant at all under the "admin
	 * domain" — schemas, collectionsMeta, or a non-empty utils allow.
	 * Used to gate surfaces (e.g. MCP admin tools) that expose structural
	 * metadata rather than plain collection content.
	 */
	public function hasAdminDomainGrants(): bool
	{
		return $this->remember('hasAdminDomainGrants', function (): bool {
			if ($this->isAdmin) {
				return true;
			}

			foreach ($this->groups as $group) {
				if ($this->grantsAnyOperation($group->permissions['schemas'] ?? [])) {
					return true;
				}

				if ($this->grantsAnyOperation($group->permissions['collectionsMeta'] ?? [])) {
					return true;
				}

				$utils = $group->permissions['utils'] ?? [];
				if (($utils['all'] ?? false) === true || (($utils['allowed'] ?? []) !== [])) {
					return true;
				}
			}

			return false;
		});
	}

	/**
	 * A permissions block ('schemas' / 'collectionsMeta' shaped: all, allowed,
	 * operations) grants something only when it has at least one operation
	 * AND a target to apply it to (all=true or a non-empty allowed list).
	 *
	 * @param array<string,mixed> $permissions
	 */
	private function grantsAnyOperation(array $permissions): bool
	{
		$operations = $permissions['operations'] ?? [];
		if ($operations === []) {
			return false;
		}

		return ($permissions['all'] ?? false) === true || (($permissions['allowed'] ?? []) !== []);
	}

	private function remember(string $key, \Closure $resolve): bool
	{
		return $this->memo[$key] ??= $resolve();
	}
}

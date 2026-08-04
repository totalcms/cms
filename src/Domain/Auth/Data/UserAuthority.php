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
	 * @return list<string>|null null = unrestricted (admin, or a group with
	 *                            collections.all=true and 'read' granted)
	 */
	public function readableCollections(): ?array
	{
		if ($this->isAdmin) {
			return null;
		}

		$collections = [];
		foreach ($this->groups as $group) {
			$permissions = $group->permissions['collections'] ?? [];
			$operations  = $permissions['operations'] ?? [];

			if (!in_array('read', $operations, true)) {
				continue;
			}

			if (($permissions['all'] ?? false) === true) {
				return null;
			}

			foreach ((array)($permissions['allowed'] ?? []) as $collection) {
				$collections[(string)$collection] = true;
			}
		}

		return array_keys($collections);
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

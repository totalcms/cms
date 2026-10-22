<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;

/**
 * Create, replace or change an access group from a JSON definition, for
 * `tcms access-group:set` and `access-group:patch`.
 *
 * The admin form builds a fixed shape, but the repository stores whatever it
 * is handed, and a misspelled section or operation does not fail: it saves a
 * group that quietly grants nothing. So every definition is checked against
 * the stored shape first, and an unknown key, operation or type is refused
 * with the name of what was wrong.
 *
 * The shape, under `permissions`:
 *   - collections, collectionsMeta, schemas: {operations, all, allowed}
 *     — operations from create/read/update/delete, allowed a list of ids;
 *   - utils, settings, extensions: {all, allowed};
 *   - builder, mailer, playground, dataviews, docs, inlineEdit: true/false.
 */
final readonly class AccessGroupEditor
{
	private const OPERATIONS = ['create', 'read', 'update', 'delete'];

	private const TARGETED = ['collectionsMeta', 'collections', 'schemas'];

	private const LISTED = ['utils', 'settings', 'extensions'];

	private const FLAGS = ['builder', 'mailer', 'playground', 'dataviews', 'docs', 'inlineEdit'];

	public function __construct(
		private AccessGroupLister $lister,
		private AccessGroupManager $manager,
	) {
	}

	/**
	 * Create group $id, or replace it, from a full definition. A section left
	 * out grants nothing.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @throws \DomainException  a definition that does not fit the shape
	 * @throws \RuntimeException the admin group, which cannot be changed
	 */
	public function set(string $id, array $definition): AccessGroupData
	{
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) !== 1) {
			throw new \DomainException("Invalid access group id '{$id}': use letters, digits, - and _.");
		}
		$this->assertShape($id, $definition);

		$permissions = self::nothingGranted();
		foreach ((array)($definition['permissions'] ?? []) as $section => $value) {
			$permissions[$section] = is_array($value) ? array_merge($permissions[$section], $value) : $value;
		}

		return $this->save($id, (string)($definition['description'] ?? ''), (array)($definition['operations'] ?? []), $permissions);
	}

	/**
	 * Change existing group $id. The description and top-level operations are
	 * replaced when sent; each permission section merges key by key, so
	 * `{"permissions":{"collections":{"allowed":["blog"]}}}` keeps the
	 * section's operations.
	 *
	 * @param array<string,mixed> $patch
	 *
	 * @throws \DomainException  a missing group, or a patch that does not fit the shape
	 * @throws \RuntimeException the admin group, which cannot be changed
	 */
	public function patch(string $id, array $patch): AccessGroupData
	{
		$group = $this->lister->findById($id);
		if (!$group instanceof AccessGroupData) {
			throw new \DomainException("Access group '{$id}' not found. Use access-group:set to create it.");
		}
		$this->assertShape($id, $patch);

		$permissions = array_replace(self::nothingGranted(), $group->permissions);
		foreach ((array)($patch['permissions'] ?? []) as $section => $value) {
			$permissions[$section] = is_array($value) && is_array($permissions[$section] ?? null)
				? array_merge($permissions[$section], $value)
				: $value;
		}

		return $this->save(
			$id,
			(string)($patch['description'] ?? $group->description),
			(array)($patch['operations'] ?? $group->operations),
			$permissions,
		);
	}

	/**
	 * @param array<mixed>        $operations
	 * @param array<string,mixed> $permissions
	 */
	private function save(string $id, string $description, array $operations, array $permissions): AccessGroupData
	{
		// As the admin form does: a section granted for all keeps no list.
		foreach ([...self::TARGETED, ...self::LISTED] as $section) {
			if (($permissions[$section]['all'] ?? false) === true) {
				$permissions[$section]['allowed'] = [];
			}
		}

		$group = new AccessGroupData([
			'id'          => $id,
			'description' => $description,
			'operations'  => array_values($operations),
			'permissions' => $permissions,
		]);
		$this->manager->save($group);

		return $group;
	}

	/**
	 * Refuse a definition that does not fit the stored shape.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @throws \DomainException
	 */
	private function assertShape(string $id, array $definition): void
	{
		if (isset($definition['id']) && $definition['id'] !== $id) {
			throw new \DomainException('An access group cannot be renamed. Create a new group instead.');
		}

		$unknown = array_diff(array_keys($definition), ['id', 'description', 'operations', 'permissions']);
		if ($unknown !== []) {
			throw new \DomainException(sprintf('Unknown key "%s". An access group has description, operations and permissions.', (string)reset($unknown)));
		}
		if (isset($definition['description']) && !is_string($definition['description'])) {
			throw new \DomainException('description must be a string.');
		}
		if (array_key_exists('operations', $definition)) {
			self::assertOperations('operations', $definition['operations']);
		}

		$permissions = $definition['permissions'] ?? [];
		if (!is_array($permissions) || (array_is_list($permissions) && $permissions !== [])) {
			throw new \DomainException('permissions must be an object of sections.');
		}
		foreach ($permissions as $section => $value) {
			self::assertSection((string)$section, $value);
		}
	}

	/**
	 * @throws \DomainException
	 */
	private static function assertSection(string $section, mixed $value): void
	{
		if (in_array($section, self::FLAGS, true)) {
			if (!is_bool($value)) {
				throw new \DomainException("permissions.{$section} must be true or false.");
			}

			return;
		}

		$targeted = in_array($section, self::TARGETED, true);
		if (!$targeted && !in_array($section, self::LISTED, true)) {
			throw new \DomainException(sprintf(
				'Unknown permission section "%s". Sections: %s.',
				$section,
				implode(', ', [...self::TARGETED, ...self::LISTED, ...self::FLAGS]),
			));
		}
		if (!is_array($value) || (array_is_list($value) && $value !== [])) {
			throw new \DomainException("permissions.{$section} must be an object.");
		}

		self::assertSectionKeys($section, $value, $targeted ? ['operations', 'all', 'allowed'] : ['all', 'allowed']);
	}

	/**
	 * Refuse a key the section does not take, or one of the wrong type.
	 *
	 * @param array<mixed> $value
	 * @param list<string> $keys
	 *
	 * @throws \DomainException
	 */
	private static function assertSectionKeys(string $section, array $value, array $keys): void
	{
		$unknown = array_diff(array_keys($value), $keys);
		if ($unknown !== []) {
			throw new \DomainException(sprintf('Unknown key "%s" in permissions.%s. It takes %s.', (string)reset($unknown), $section, implode(', ', $keys)));
		}
		if (array_key_exists('operations', $value)) {
			self::assertOperations("permissions.{$section}.operations", $value['operations']);
		}
		if (array_key_exists('all', $value) && !is_bool($value['all'])) {
			throw new \DomainException("permissions.{$section}.all must be true or false.");
		}
		if (array_key_exists('allowed', $value) && !self::isStringList($value['allowed'])) {
			throw new \DomainException("permissions.{$section}.allowed must be a list of ids.");
		}
	}

	/**
	 * @throws \DomainException
	 */
	private static function assertOperations(string $path, mixed $operations): void
	{
		if (!self::isStringList($operations)) {
			throw new \DomainException("{$path} must be a list of operations.");
		}

		$unknown = array_diff($operations, self::OPERATIONS);
		if ($unknown !== []) {
			throw new \DomainException(sprintf('Unknown operation "%s" in %s. Operations: %s.', (string)reset($unknown), $path, implode(', ', self::OPERATIONS)));
		}
	}

	/**
	 * @phpstan-assert-if-true list<string> $value
	 */
	private static function isStringList(mixed $value): bool
	{
		return is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value;
	}

	/**
	 * Every section, granting nothing.
	 *
	 * @return array<string,mixed>
	 */
	private static function nothingGranted(): array
	{
		$permissions = [];
		foreach (self::TARGETED as $section) {
			$permissions[$section] = ['operations' => [], 'all' => false, 'allowed' => []];
		}
		foreach (self::LISTED as $section) {
			$permissions[$section] = ['all' => false, 'allowed' => []];
		}
		foreach (self::FLAGS as $flag) {
			$permissions[$flag] = false;
		}

		return $permissions;
	}
}

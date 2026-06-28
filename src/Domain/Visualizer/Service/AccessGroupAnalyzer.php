<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Builds a complete "what can each access group do" model across all live resources.
 * Every resource appears for every group so consumers can show gaps as well as grants.
 */
readonly class AccessGroupAnalyzer
{
	/**
	 * Canonical util page identifiers, sourced from the access-group form checklist
	 * (resources/templates/admin/utils/access-groups/form.twig).
	 *
	 * Does NOT include the super-admin-only utils (see {@see AccessControlService::SUPER_ADMIN_ONLY_UTILS}),
	 * which are appended separately in {@see self::resolveUtilsDimension()} so they
	 * can be rendered with their own enforcement semantics.
	 *
	 * No shared PHP source of truth exists for this list — it hand-mirrors the
	 * `utils-allowed` checklist in form.twig. The test
	 * `testSuperAdminOnlyUtilsAreSubsetOfAnalyzerUtilColumns` asserts that every
	 * entry in `SUPER_ADMIN_ONLY_UTILS` appears in the full util column set so
	 * drift between the two consts is caught at CI time.
	 *
	 * @var list<string>
	 */
	private const UTIL_PAGES = [
		'cache',
		'collection-report',
		'image-batcher',
		'import',
		'jobqueue',
		'license',
		'log-analyzer',
		'orphan-scanner',
		'pretty-url',
		'project-setup',
		'server-checker',
		'twig-debugger',
	];

	/** Full CRUD operations used for super-admin and all=true resolution. */
	private const ALL_OPS = ['read', 'create', 'update', 'delete'];

	public function __construct(
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private ExtensionManager $extensionManager,
		private AccessGroupLister $accessGroupLister,
	) {
	}

	/**
	 * Analyse every access group against every live resource.
	 *
	 * @return list<array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array{
	 *     collections: array<string, array{ops: list<string>, all: bool}>,
	 *     collectionsMeta: array<string, array{ops: list<string>, all: bool}>,
	 *     schemas: array<string, array{ops: list<string>, all: bool}>,
	 *     extensions: array<string, array{ops: list<string>, all: bool}>,
	 *     utils: array<string, array{ops: list<string>, all: bool}>,
	 *   }
	 * }>
	 */
	public function analyze(): array
	{
		$collectionIds = array_values(array_map(
			fn ($c): string => $c->id,
			$this->collectionLister->listAllCollections(),
		));
		$schemaIds = array_values(array_map(
			fn ($s): string => $s->id,
			$this->schemaLister->listAllSchemas(),
		));
		// Use listExtensionsWithAdminSurface() so the matrix columns match the
		// enforcer (AccessControlService::canAccessExtension / groupCanAccessExtension)
		// and the editable toggles on the access-group form, both of which only
		// govern extensions that have an admin surface.
		$extensionIds = array_keys($this->extensionManager->listExtensionsWithAdminSurface());

		$result = [];
		foreach ($this->accessGroupLister->listAll() as $group) {
			$result[] = $this->analyzeGroup($group, $collectionIds, $schemaIds, $extensionIds);
		}

		return $result;
	}

	/**
	 * @param list<string> $collectionIds
	 * @param list<string> $schemaIds
	 * @param list<string> $extensionIds
	 * @return array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array{
	 *     collections: array<string, array{ops: list<string>, all: bool}>,
	 *     collectionsMeta: array<string, array{ops: list<string>, all: bool}>,
	 *     schemas: array<string, array{ops: list<string>, all: bool}>,
	 *     extensions: array<string, array{ops: list<string>, all: bool}>,
	 *     utils: array<string, array{ops: list<string>, all: bool}>,
	 *   }
	 * }
	 */
	private function analyzeGroup(
		AccessGroupData $group,
		array $collectionIds,
		array $schemaIds,
		array $extensionIds,
	): array {
		$isSuperAdmin = $group->id === UserValidationService::ADMINGROUP;

		return [
			'id'          => $group->id,
			'name'        => $group->description,
			'isSuperAdmin' => $isSuperAdmin,
			'dimensions'  => [
				'collections'     => $this->resolveCollectionDimension($group, $collectionIds, $isSuperAdmin),
				'collectionsMeta' => $this->resolveCollectionMetaDimension($group, $collectionIds, $isSuperAdmin),
				'schemas'         => $this->resolveSchemasDimension($group, $schemaIds, $isSuperAdmin),
				'extensions'      => $this->resolveExtensionsDimension($group, $extensionIds, $isSuperAdmin),
				'utils'           => $this->resolveUtilsDimension($group, $isSuperAdmin),
			],
		];
	}

	/**
	 * @param list<string> $collectionIds
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveCollectionDimension(
		AccessGroupData $group,
		array $collectionIds,
		bool $isSuperAdmin,
	): array {
		return $this->resolveResourceDimension(
			$collectionIds,
			$group->permissions['collections'] ?? [],
			$isSuperAdmin,
		);
	}

	/**
	 * @param list<string> $collectionIds
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveCollectionMetaDimension(
		AccessGroupData $group,
		array $collectionIds,
		bool $isSuperAdmin,
	): array {
		return $this->resolveResourceDimension(
			$collectionIds,
			$group->permissions['collectionsMeta'] ?? [],
			$isSuperAdmin,
		);
	}

	/**
	 * @param list<string> $schemaIds
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveSchemasDimension(
		AccessGroupData $group,
		array $schemaIds,
		bool $isSuperAdmin,
	): array {
		return $this->resolveResourceDimension(
			$schemaIds,
			$group->permissions['schemas'] ?? [],
			$isSuperAdmin,
		);
	}

	/**
	 * @param list<string> $extensionIds
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveExtensionsDimension(
		AccessGroupData $group,
		array $extensionIds,
		bool $isSuperAdmin,
	): array {
		if ($isSuperAdmin) {
			return $this->fullAccessMap($extensionIds);
		}

		$permissions = $group->permissions['extensions'] ?? ['all' => true, 'allowed' => []];
		$all         = (bool)($permissions['all'] ?? false);
		$allowed     = (array)($permissions['allowed'] ?? []);

		$result = [];
		foreach ($extensionIds as $id) {
			$granted = $all || in_array($id, $allowed, true);
			$result[$id] = [
				'ops' => $granted ? ['access'] : [],
				'all' => $granted && $all,
			];
		}

		return $result;
	}

	/**
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveUtilsDimension(AccessGroupData $group, bool $isSuperAdmin): array
	{
		/** Full util column set: regular pages + super-admin-only pages. */
		$allUtils        = array_merge(self::UTIL_PAGES, AccessControlService::SUPER_ADMIN_ONLY_UTILS);
		$superAdminOnly  = AccessControlService::SUPER_ADMIN_ONLY_UTILS;

		if ($isSuperAdmin) {
			return $this->fullAccessMap($allUtils);
		}

		$permissions = $group->permissions['utils'] ?? [];
		$all         = (bool)($permissions['all'] ?? false);
		$allowed     = (array)($permissions['allowed'] ?? []);

		$result = [];
		foreach ($allUtils as $util) {
			// Super-admin-only utils are hard-denied for every non-super-admin group,
			// mirroring the early-return in AccessControlService::canAccessUtils().
			if (in_array($util, $superAdminOnly, true)) {
				$result[$util] = ['ops' => [], 'all' => false];
				continue;
			}

			$granted       = $all || in_array($util, $allowed, true);
			$result[$util] = [
				'ops' => $granted ? ['access'] : [],
				'all' => $granted && $all,
			];
		}

		return $result;
	}

	/**
	 * Generic resolver for dimensions that use {all, allowed, operations} shape
	 * (collections, collectionsMeta, schemas).
	 *
	 * @param list<string>         $resourceIds
	 * @param array<string,mixed>  $permissions
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveResourceDimension(
		array $resourceIds,
		array $permissions,
		bool $isSuperAdmin,
	): array {
		if ($isSuperAdmin) {
			return $this->fullAccessMap($resourceIds);
		}

		$all        = (bool)($permissions['all'] ?? false);
		$allowed    = (array)($permissions['allowed'] ?? []);
		$operations = array_values((array)($permissions['operations'] ?? []));

		$result = [];
		foreach ($resourceIds as $id) {
			if ($all || in_array($id, $allowed, true)) {
				$result[$id] = ['ops' => $operations, 'all' => $all];
			} else {
				$result[$id] = ['ops' => [], 'all' => false];
			}
		}

		return $result;
	}

	/**
	 * Build a full-access entry map for super-admin resolution.
	 *
	 * @param list<string> $ids
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function fullAccessMap(array $ids): array
	{
		$result = [];
		foreach ($ids as $id) {
			$result[$id] = ['ops' => self::ALL_OPS, 'all' => true];
		}

		return $result;
	}
}

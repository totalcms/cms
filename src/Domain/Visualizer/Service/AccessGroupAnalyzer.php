<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
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
		private McpSchemaResolver $mcpSchemaResolver,
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
	 *     mcp: array<string, array{ops: list<string>, all: bool}>,
	 *     extensions: array<string, array{ops: list<string>, all: bool}>,
	 *     utils: array<string, array{ops: list<string>, all: bool}>,
	 *   }
	 * }>
	 */
	public function analyze(): array
	{
		$collections   = $this->collectionLister->listAllCollections();
		$collectionIds = array_values(array_map(
			fn (CollectionData $c): string => $c->id,
			$collections,
		));
		$schemaIds = array_values(array_map(
			fn (\TotalCMS\Domain\Schema\Data\SchemaData $s): string => $s->id,
			$this->schemaLister->listAllSchemas(),
		));
		// Use listExtensionsWithAdminSurface() so the matrix columns match the
		// enforcer (AccessControlService::canAccessExtension / groupCanAccessExtension)
		// and the editable toggles on the access-group form, both of which only
		// govern extensions that have an admin surface.
		$extensionIds = array_keys($this->extensionManager->listExtensionsWithAdminSurface());
		$mcpExposure  = $this->resolveMcpExposure($collections);

		$result = [];
		foreach ($this->accessGroupLister->listAll() as $group) {
			$result[] = $this->analyzeGroup($group, $collectionIds, $schemaIds, $extensionIds, $mcpExposure);
		}

		return $result;
	}

	/**
	 * @param list<string> $collectionIds
	 * @param list<string> $schemaIds
	 * @param list<string> $extensionIds
	 * @param array<string, string> $mcpExposure collection id → mcp.access ('public'|'authenticated'), MCP-exposed collections only
	 *
	 * @return array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array{
	 *     collections: array<string, array{ops: list<string>, all: bool}>,
	 *     collectionsMeta: array<string, array{ops: list<string>, all: bool}>,
	 *     schemas: array<string, array{ops: list<string>, all: bool}>,
	 *     mcp: array<string, array{ops: list<string>, all: bool}>,
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
		array $mcpExposure,
	): array {
		$isSuperAdmin = $group->id === UserValidationService::ADMINGROUP;

		return [
			'id'           => $group->id,
			'name'         => $group->description,
			'isSuperAdmin' => $isSuperAdmin,
			'dimensions'   => [
				'collections'     => $this->resolveCollectionDimension($group, $collectionIds, $isSuperAdmin),
				'collectionsMeta' => $this->resolveCollectionMetaDimension($group, $collectionIds, $isSuperAdmin),
				'schemas'         => $this->resolveSchemasDimension($group, $schemaIds, $isSuperAdmin),
				'mcp'             => $this->resolveMcpDimension($group, $mcpExposure, $isSuperAdmin),
				'extensions'      => $this->resolveExtensionsDimension($group, $extensionIds, $isSuperAdmin),
				'utils'           => $this->resolveUtilsDimension($group, $isSuperAdmin),
			],
		];
	}

	/**
	 * Determine which collections are actually reachable via MCP at all, i.e.
	 * whose `mcp.access` (defaults to `'admin'` — not exposed) is `'public'` or
	 * `'authenticated'`. Collections at the default `'admin'` access are simply
	 * absent from the returned map, which in turn keeps them out of every
	 * group's `mcp` dimension entries — {@see AccessGroupMatrixPresenter}
	 * only creates a column for resource ids that appear in at least one
	 * group's dimension, so a site that hasn't turned on MCP for anything
	 * gets zero `mcp` columns and the whole dimension is hidden.
	 *
	 * @param array<CollectionData> $collections
	 *
	 * @return array<string, string> collection id → mcp.access ('public'|'authenticated')
	 */
	private function resolveMcpExposure(array $collections): array
	{
		$exposed = [];
		foreach ($collections as $collection) {
			$access = $this->mcpSchemaResolver->forCollection($collection)['access'];
			if ($access === 'public' || $access === 'authenticated') {
				$exposed[$collection->id] = $access;
			}
		}

		return $exposed;
	}

	/**
	 * Resolve the `mcp` (AI / MCP reach) dimension — reuses the group's
	 * `collections` permission grants (the same ones REST/CRUD write tools
	 * enforce), reinterpreted under MCP's actual rules:
	 *
	 *   - `read`   → set when the collection is `mcp.access: 'public'`
	 *                (exposure alone is enough, mirrors
	 *                {@see \TotalCMS\Domain\Mcp\Auth\Service\PersonaContext::canReadCollection()}'s
	 *                public carve-out) OR the group grants `read`.
	 *   - `create` → set when the group grants `create`. No separate exposure
	 *                check is needed here: every collection in $mcpExposure is
	 *                already 'public' or 'authenticated', both of which satisfy
	 *                {@see McpSchemaResolver::isAccessibleTo()} for the
	 *                'authenticated' persona — the same gate
	 *                {@see \TotalCMS\Domain\Mcp\Tool\Admin\ObjectTools::requireExposed()}
	 *                applies before every write tool call.
	 *   - `update` → same rule as `create`.
	 *   - `delete` → NEVER set. MCP ships no delete tool at all
	 *                (create_object/update_object/patch_object are the only
	 *                write tools) — a permanently-absent D is honest.
	 *
	 * `all` is deliberately never derived from the group's own config for a
	 * non-super-admin row, even when `collections.all: true` with every
	 * operation granted — the presenter's `ALL` cell sentinel would imply
	 * delete is possible too, which MCP can never grant. Only the
	 * super-admin path below uses it, consistent with every other dimension's
	 * super-admin treatment.
	 *
	 * @param array<string, string> $mcpExposure collection id → mcp.access, MCP-exposed collections only
	 *
	 * @return array<string, array{ops: list<string>, all: bool}>
	 */
	private function resolveMcpDimension(AccessGroupData $group, array $mcpExposure, bool $isSuperAdmin): array
	{
		if ($isSuperAdmin) {
			return $this->fullAccessMap(array_keys($mcpExposure));
		}

		$permissions = $group->permissions['collections'] ?? [];
		$all         = (bool)($permissions['all'] ?? false);
		$allowed     = (array)($permissions['allowed'] ?? []);
		$opsSet      = array_flip(array_values((array)($permissions['operations'] ?? [])));
		$granted     = static fn (string $id): bool => $all || in_array($id, $allowed, true);

		$result = [];
		foreach ($mcpExposure as $id => $access) {
			$hasGrant = $granted($id);
			$ops      = [];

			if ($access === 'public' || ($hasGrant && isset($opsSet['read']))) {
				$ops[] = 'read';
			}
			if ($hasGrant && isset($opsSet['create'])) {
				$ops[] = 'create';
			}
			if ($hasGrant && isset($opsSet['update'])) {
				$ops[] = 'update';
			}

			$result[$id] = ['ops' => $ops, 'all' => false];
		}

		return $result;
	}

	/**
	 * @param list<string> $collectionIds
	 *
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
	 *
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
	 *
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
	 *
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
			$granted     = $all || in_array($id, $allowed, true);
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
	 *
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
	 *
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

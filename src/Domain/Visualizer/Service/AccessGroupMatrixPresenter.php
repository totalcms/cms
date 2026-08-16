<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

/**
 * Transforms the raw AccessGroupAnalyzer model into a view-ready matrix
 * that a Twig template can render directly without any additional logic.
 *
 * ## Output shape
 *
 * ```
 * array{
 *   columnGroups: list<array{
 *     dimension: string,
 *     label: string,
 *     columns: list<array{id: string, label: string}>,
 *   }>,
 *   rows: list<array{
 *     id: string,
 *     name: string,
 *     isSuperAdmin: bool,
 *     cells: array<string, string>,
 *   }>,
 * }
 * ```
 *
 * ## Cell-string convention
 *
 * Cells are keyed `"<dimension>:<resourceId>"`.
 *
 * ### CRUD dimensions (collections, collectionsMeta, schemas, mcp)
 *   - `all = true`                  → `"ALL"`
 *   - All four ops present, all=false → `"CRUD"`
 *   - Partial ops               → fixed C/R/U/D order; present letter, `·` for absent (e.g. read+update → `"·R·U"`)
 *   - No ops                    → `""` (empty string)
 *
 * `mcp` is a CRUD dimension too, but its producer ({@see AccessGroupAnalyzer::resolveMcpDimension()})
 * never emits a `delete` op for a non-super-admin row (MCP has no delete tool), so the
 * `D` position is always `·` there — this presenter has no MCP-specific knowledge, it just
 * formats whatever ops the analyzer hands it.
 *
 * ### Access dimensions (utils, extensions) — single-op (`access`)
 *   - `all = true`      → `"ALL"`
 *   - `access` present  → `"✓"`
 *   - no access         → `"·"`
 *
 * ## Dimension order
 *   Schemas → Collection Settings → Collection Objects → AI / MCP → Utils → Extensions
 *
 * ## Row ordering
 *   Non-super-admin rows alphabetically by name, super-admin rows last (also alphabetical among themselves).
 */
readonly class AccessGroupMatrixPresenter
{
	/** Canonical dimension order and their human-readable labels. */
	private const DIMENSION_ORDER = [
		'schemas'         => 'Schemas',
		'collectionsMeta' => 'Collection Settings',
		'collections'     => 'Collection Objects',
		'mcp'             => 'AI / MCP',
		'utils'           => 'Utils',
		'extensions'      => 'Extensions',
	];

	/** CRUD dimensions use C/R/U/D letter encoding; all others use access (✓/·) encoding. */
	private const CRUD_DIMENSIONS = ['collections', 'collectionsMeta', 'schemas', 'mcp'];

	/** Fixed CRUD letter map: op-name → display letter. */
	private const OP_LETTERS = [
		'create' => 'C',
		'read'   => 'R',
		'update' => 'U',
		'delete' => 'D',
	];

	/**
	 * Convert an AccessGroupAnalyzer model into a view-ready matrix.
	 *
	 * @param list<array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array<string, array<string, array{ops: list<string>, all: bool}>>,
	 * }> $model
	 *
	 * @return array{
	 *   columnGroups: list<array{dimension: string, label: string, columns: list<array{id: string, label: string}>}>,
	 *   rows: list<array{id: string, name: string, isSuperAdmin: bool, cells: array<string, string>}>,
	 * }
	 */
	public function present(array $model): array
	{
		$resourceIds  = $this->collectResourceIds($model);
		$columnGroups = $this->buildColumnGroups($resourceIds);
		$rows         = $this->buildRows($model, $resourceIds);

		return [
			'columnGroups' => $columnGroups,
			'rows'         => $rows,
		];
	}

	/**
	 * Walk all groups and collect the union of resource IDs per dimension.
	 *
	 * @param list<array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array<string, array<string, array{ops: list<string>, all: bool}>>,
	 * }> $model
	 *
	 * @return array<string, list<string>>  dimension → sorted resource IDs
	 */
	private function collectResourceIds(array $model): array
	{
		/** @var array<string, array<string, true>> $seen */
		$seen = [];

		foreach ($model as $group) {
			foreach ($group['dimensions'] as $dim => $resources) {
				foreach (array_keys($resources) as $resourceId) {
					$seen[$dim][$resourceId] = true;
				}
			}
		}

		$result = [];
		foreach (array_keys(self::DIMENSION_ORDER) as $dim) {
			$ids = array_keys($seen[$dim] ?? []);
			sort($ids);
			$result[$dim] = $ids;
		}

		return $result;
	}

	/**
	 * Build the ordered column-group structure for the template header row.
	 *
	 * @param array<string, list<string>> $resourceIds
	 *
	 * @return list<array{dimension: string, label: string, columns: list<array{id: string, label: string}>}>
	 */
	private function buildColumnGroups(array $resourceIds): array
	{
		$groups = [];
		foreach (self::DIMENSION_ORDER as $dim => $label) {
			$columns = [];
			foreach ($resourceIds[$dim] as $id) {
				$columns[] = ['id' => $id, 'label' => $id];
			}
			$groups[] = [
				'dimension' => $dim,
				'label'     => $label,
				'columns'   => $columns,
			];
		}

		return $groups;
	}

	/**
	 * Build the row list sorted non-super-admin alphabetically first, super-admin last.
	 *
	 * @param list<array{
	 *   id: string,
	 *   name: string,
	 *   isSuperAdmin: bool,
	 *   dimensions: array<string, array<string, array{ops: list<string>, all: bool}>>,
	 * }> $model
	 * @param array<string, list<string>> $resourceIds
	 *
	 * @return list<array{id: string, name: string, isSuperAdmin: bool, cells: array<string, string>}>
	 */
	private function buildRows(array $model, array $resourceIds): array
	{
		$normal = [];
		$admins = [];

		foreach ($model as $group) {
			$row = [
				'id'           => $group['id'],
				'name'         => $group['name'] !== '' ? $group['name'] : $group['id'],
				'isSuperAdmin' => $group['isSuperAdmin'],
				'cells'        => $this->buildCells($group['dimensions'], $resourceIds),
			];

			if ($group['isSuperAdmin']) {
				$admins[] = $row;
			} else {
				$normal[] = $row;
			}
		}

		usort($normal, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
		usort($admins, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		return array_merge($normal, $admins);
	}

	/**
	 * Build the cells map for a single group row.
	 *
	 * @param array<string, array<string, array{ops: list<string>, all: bool}>> $dimensions
	 * @param array<string, list<string>> $resourceIds
	 *
	 * @return array<string, string>
	 */
	private function buildCells(array $dimensions, array $resourceIds): array
	{
		$cells = [];

		foreach (array_keys(self::DIMENSION_ORDER) as $dim) {
			$resources = $dimensions[$dim] ?? [];
			$isCrud    = in_array($dim, self::CRUD_DIMENSIONS, true);

			foreach ($resourceIds[$dim] as $resourceId) {
				$entry = $resources[$resourceId] ?? ['ops' => [], 'all' => false];
				$key   = $dim . ':' . $resourceId;

				$cells[$key] = $isCrud
					? $this->formatCrudCell($entry)
					: $this->formatAccessCell($entry);
			}
		}

		return $cells;
	}

	/**
	 * Format a CRUD-style cell (collections, collectionsMeta, schemas).
	 *
	 * @param array{ops: list<string>, all: bool} $entry
	 */
	private function formatCrudCell(array $entry): string
	{
		if ($entry['all']) {
			return 'ALL';
		}

		if ($entry['ops'] === []) {
			return '';
		}

		$opsSet = array_flip($entry['ops']);
		$cell   = '';
		foreach (self::OP_LETTERS as $op => $letter) {
			$cell .= isset($opsSet[$op]) ? $letter : '·';
		}

		// Trim trailing dots when only leading ops are absent (keep meaningful padding)
		// Convention: always emit all 4 positions so alignment is consistent.
		return $cell;
	}

	/**
	 * Format an access-style cell (utils, extensions).
	 *
	 * @param array{ops: list<string>, all: bool} $entry
	 */
	private function formatAccessCell(array $entry): string
	{
		if ($entry['all']) {
			return 'ALL';
		}

		return in_array('access', $entry['ops'], true) ? '✓' : '·';
	}
}

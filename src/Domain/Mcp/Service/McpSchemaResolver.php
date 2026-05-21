<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Query\Service\ObjectFilter;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Resolves the effective MCP configuration for a collection, merging stored
 * values with sensible defaults and per-field-type inferences.
 *
 * Phase 1 surface: this is the canonical place to ask "is collection X public
 * to MCP?", "which fields are filterable on Y?", "should this property be
 * stripped from responses for persona Z?". `ListCollectionsTool` and the
 * dynamic-description builder both consume this.
 *
 * No schema-level mcp defaults — values live only at the collection level (in
 * `.meta.json` via CollectionData.mcp) and per-property in the schema's
 * `properties.{name}.mcp` sub-key. Defaults apply when stored values are
 * absent, never overriding explicit operator choices.
 */
readonly class McpSchemaResolver
{
	/**
	 * Field-type capability defaults live on SchemaData (the canonical catalog)
	 * and are consulted through ObjectFilter::isFilterableType() /
	 * CollectionSorter::isSortableType(). Per-property operator overrides
	 * (mcp.filterable / mcp.sortable) win when set — see resolveFilterable /
	 * resolveSortable below.
	 */

	/** Catalog cap shared with the dynamic-description builder. */
	public const DEFAULT_CATALOG_CAP = 30;

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private McpDescriptionResolver $descriptions,
		private CollectionRepository $collections,
	) {
	}

	/**
	 * Effective MCP config for a collection, with defaults applied.
	 *
	 * @return array{access: string, description: ?string, resource: bool}
	 */
	public function forCollection(CollectionData $collection): array
	{
		$mcp = $collection->mcp;

		$access = (string)($mcp['access'] ?? 'admin');
		if (!in_array($access, ['admin', 'public', 'authenticated'], true)) {
			$access = 'admin';
		}

		return [
			'access'      => $access,
			'description' => $this->descriptions->forCollection($collection),
			'resource'    => (bool)($mcp['resource'] ?? true),
		];
	}

	/**
	 * Whether a persona may access this collection via MCP content tools.
	 * Mirror policy of McpToolDefinition::isVisibleTo for collections.
	 */
	public function isAccessibleTo(CollectionData $collection, string $persona): bool
	{
		$access = $this->forCollection($collection)['access'];

		if ($persona === 'admin') {
			return true;
		}
		if ($persona === 'public') {
			return $access === 'public';
		}
		if ($persona === 'authenticated') {
			return $access === 'public' || $access === 'authenticated';
		}

		return false;
	}

	/**
	 * Returns per-property filter/sort metadata for AI consumers (used by
	 * ListCollectionsTool's filterable_fields output AND the dynamic tool
	 * description builder in Chunk B).
	 *
	 * @return list<array{name: string, type: string, description: ?string, filterable: bool, sortable: bool}>
	 */
	public function filterableFields(CollectionData $collection): array
	{
		$schema   = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		$fields   = [];

		foreach ($schema->properties as $name => $property) {
			if (!is_array($property)) {
				continue;
			}

			// Strip non-exposed fields entirely — they shouldn't appear in
			// AI-facing metadata.
			$mcp = is_array($property['mcp'] ?? null) ? $property['mcp'] : [];
			if (array_key_exists('expose', $mcp) && $mcp['expose'] === false) {
				continue;
			}

			$type = (string)($property['field'] ?? 'text');

			$fields[] = [
				'name'        => (string)$name,
				'type'        => $type,
				'description' => $this->descriptions->forProperty($property),
				'filterable'  => $this->resolveFilterable($mcp, $type),
				'sortable'    => $this->resolveSortable($mcp, $type),
			];
		}

		return $fields;
	}

	/**
	 * Names of properties marked `mcp.expose: false` on this collection's schema.
	 *
	 * Content tools call this once per request and unset each listed key from
	 * every result item — cheap, deterministic, no per-item schema lookup.
	 *
	 * @return list<string>
	 */
	public function nonExposedFields(CollectionData $collection): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		$names  = [];

		foreach ($schema->properties as $name => $property) {
			if (!is_array($property)) {
				continue;
			}

			$mcp = is_array($property['mcp'] ?? null) ? $property['mcp'] : [];
			if (array_key_exists('expose', $mcp) && $mcp['expose'] === false) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}

	/**
	 * Renders the persona-scoped collection/field catalog appended to content-tool
	 * descriptions. Format per line:
	 *
	 *   - <id> — <field> (<type>[, sortable]), <field> (<type>[, sortable])
	 *
	 * Fields that are neither filterable nor sortable are omitted from the line —
	 * they aren't actionable for query composition, so they don't deserve the
	 * tokens. When a collection has zero filterable/sortable fields the line
	 * carries an explicit "(no filterable fields)" marker so the agent doesn't
	 * try to compose include/exclude against it.
	 *
	 * Returns empty string when zero collections are visible (fresh install or
	 * a public persona on a site with no public collections); callers compose
	 * their own base description and skip the catalog block.
	 *
	 * Capped at $cap visible collections; overflow rolls into a closing pointer
	 * at `list_collections` so the agent has a known fallback.
	 */
	public function renderCatalog(McpPersona $persona, int $cap = self::DEFAULT_CATALOG_CAP): string
	{
		$visible = array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $collection): bool => $this->isAccessibleTo($collection, $persona->value),
		);

		if ($visible === []) {
			return '';
		}

		// Stable order so identical sites generate identical tool descriptions.
		usort($visible, static fn (CollectionData $a, CollectionData $b): int => strcmp($a->id, $b->id));

		$overflow = max(0, count($visible) - $cap);
		$shown    = array_slice($visible, 0, $cap);

		$lines = ['Available collections and filterable fields:'];
		foreach ($shown as $collection) {
			$lines[] = '- ' . $collection->id . ' — ' . $this->renderFieldList($collection);
		}

		if ($overflow > 0) {
			$lines[] = sprintf('Plus %d more — call list_collections for the full list.', $overflow);
		}

		return implode("\n", $lines);
	}

	private function renderFieldList(CollectionData $collection): string
	{
		$parts = [];
		foreach ($this->filterableFields($collection) as $field) {
			if (!$field['filterable'] && !$field['sortable']) {
				continue;
			}

			$attrs = [$field['type']];
			if ($field['sortable']) {
				$attrs[] = 'sortable';
			}

			$parts[] = $field['name'] . ' (' . implode(', ', $attrs) . ')';
		}

		return $parts === [] ? '(no filterable fields)' : implode(', ', $parts);
	}

	/**
	 * Whether a specific property is exposed to MCP responses.
	 * Used by content tools to strip non-exposed fields from output.
	 */
	public function isPropertyExposed(SchemaData $schema, string $propertyName): bool
	{
		$property = $schema->properties[$propertyName] ?? null;
		if (!is_array($property)) {
			return true;
		}

		$mcp = is_array($property['mcp'] ?? null) ? $property['mcp'] : [];

		return !(array_key_exists('expose', $mcp) && $mcp['expose'] === false);
	}

	/**
	 * @param array<string,mixed> $mcp
	 */
	private function resolveFilterable(array $mcp, string $fieldType): bool
	{
		if (array_key_exists('filterable', $mcp)) {
			return (bool)$mcp['filterable'];
		}

		return ObjectFilter::isFilterableType($fieldType);
	}

	/**
	 * @param array<string,mixed> $mcp
	 */
	private function resolveSortable(array $mcp, string $fieldType): bool
	{
		if (array_key_exists('sortable', $mcp)) {
			return (bool)$mcp['sortable'];
		}

		return CollectionSorter::isSortableType($fieldType);
	}
}

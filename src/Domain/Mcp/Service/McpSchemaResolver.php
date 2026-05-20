<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
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
	 * Field types that are filterable by default (include/exclude predicate).
	 * Operator can override by setting `mcp.filterable: false` per property.
	 */
	private const DEFAULT_FILTERABLE_FIELDS = [
		'text', 'textarea', 'styledtext', 'select', 'toggle', 'checkbox',
		'number', 'range', 'date', 'datetime', 'slug', 'email', 'url', 'phone',
	];

	/**
	 * Field types that are sortable by default. Operator can override.
	 */
	private const DEFAULT_SORTABLE_FIELDS = [
		'number', 'range', 'date', 'datetime',
	];

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private McpDescriptionResolver $descriptions,
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

		return in_array($fieldType, self::DEFAULT_FILTERABLE_FIELDS, true);
	}

	/**
	 * @param array<string,mixed> $mcp
	 */
	private function resolveSortable(array $mcp, string $fieldType): bool
	{
		if (array_key_exists('sortable', $mcp)) {
			return (bool)$mcp['sortable'];
		}

		return in_array($fieldType, self::DEFAULT_SORTABLE_FIELDS, true);
	}
}

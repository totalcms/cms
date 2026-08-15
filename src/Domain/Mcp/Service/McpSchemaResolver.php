<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
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
	 * @return array{access: string, description: ?string, resource: bool, titleProperty: string}
	 */
	public function forCollection(CollectionData $collection): array
	{
		$mcp = $collection->mcp;

		$access = (string)($mcp['access'] ?? 'admin');
		if (!in_array($access, ['admin', 'public', 'authenticated'], true)) {
			$access = 'admin';
		}

		return [
			'access'        => $access,
			'description'   => $this->descriptions->forCollection($collection),
			// Unset means off. A collection is exposed as an addressable
			// `tcms://` resource only when someone said so — matching the schema
			// default, and matching how `access` above already treats an unset
			// value as the closed option rather than the open one. An explicitly
			// stored `true` still wins, so collections that opted in stay in.
			'resource'      => (bool)($mcp['resource'] ?? false),
			'titleProperty' => is_string($mcp['titleProperty'] ?? null) ? $mcp['titleProperty'] : '',
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
	 * Per-property describe metadata for AI consumers — used by:
	 *   - `describe_collection` tool output (full property list with flags)
	 *   - Dynamic description-builder field catalogs in content tools
	 *     (filters internally to only actionable — see renderFieldList)
	 *
	 * **Index gate.** `query_collection` and `search_collection` iterate the
	 * collection's index. Properties not in `$schema->index` are physically
	 * unqueryable — they live on objects but aren't in what the filter/search
	 * code sees. We list them anyway so the agent knows they exist (to fetch
	 * via `get_object`), but flag both `filterable: false` and `sortable:
	 * false`. Operator `mcp.filterable: true` cannot promote a non-indexed
	 * property; the index is authoritative.
	 *
	 * Non-exposed properties (`mcp.expose: false`) are excluded entirely —
	 * distinct from non-indexed: the operator has marked them "not for AI."
	 *
	 * Each property entry includes an `indexed` flag — the authoritative
	 * signal for "does this property appear in query_collection /
	 * search_collection results?". Properties with `indexed: false` only
	 * surface via `get_object`. The invariant `indexed: false → filterable:
	 * false AND sortable: false` always holds; the reverse is not true (an
	 * operator-demoted indexed property reads filterable: false despite
	 * being indexed).
	 *
	 * @return list<array{name: string, type: string, description: ?string, indexed: bool, filterable: bool, sortable: bool}>
	 */
	public function describeProperties(CollectionData $collection): array
	{
		$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		$properties = [];

		foreach ($schema->properties as $name => $property) {
			if (!is_array($property)) {
				continue;
			}

			// Strip non-exposed properties entirely — they shouldn't appear in
			// AI-facing metadata at all.
			if (!$this->isPropertyArrayExposed($property)) {
				continue;
			}

			$type    = (string)($property['field'] ?? 'text');
			$indexed = in_array((string)$name, $schema->index, true);

			$properties[] = [
				'name'        => (string)$name,
				'type'        => $type,
				'description' => $this->descriptions->forProperty($property),
				'indexed'     => $indexed,
				// Index gate is the outer constraint: a non-indexed property
				// can never be filterable/sortable. The inner check is purely
				// field-type-based — operators steer this via the schema's
				// `index` list, not per-property mcp keys.
				'filterable'  => $indexed && $this->resolveFilterable($type),
				'sortable'    => $indexed && $this->resolveSortable($type),
			];
		}

		return $properties;
	}

	/**
	 * Names of properties whose values are HTML and should pass through
	 * ContentRenderer for the agent's chosen `format` (markdown/html/text).
	 *
	 * Covers `styledtext` (plain HTML string) AND `localizedstyledtext`
	 * (locale-keyed object of HTML strings — `{en_US: "<html>", de: "<html>"}`).
	 * ContentRenderer is polymorphic on input and handles both shapes.
	 *
	 * @return list<string>
	 */
	public function renderableProperties(CollectionData $collection): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		$names  = [];

		foreach ($schema->properties as $name => $property) {
			if (!is_array($property)) {
				continue;
			}

			$fieldType = (string)($property['field'] ?? '');
			if (in_array($fieldType, ['styledtext', 'localizedstyledtext'], true)) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}

	/**
	 * Names of properties marked `mcp.expose: false` on this collection's schema.
	 *
	 * Content tools call this once per request and unset each listed key from
	 * every result item — cheap, deterministic, no per-item schema lookup.
	 *
	 * @return list<string>
	 */
	public function nonExposedProperties(CollectionData $collection): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		$names  = [];

		foreach ($schema->properties as $name => $property) {
			if (!is_array($property)) {
				continue;
			}

			if (!$this->isPropertyArrayExposed($property)) {
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
	 *
	 * $isReadable (Task 10b fix round 1, finding #1): optional additional
	 * filter applied alongside the $access exposure check. Without it, an
	 * AUTHENTICATED caller's tool description would advertise every
	 * `mcp.access: authenticated` collection's id AND filterable property
	 * names — including ones query_collection/get_object/search_collection
	 * will now refuse per their group-read gate — the exact
	 * advertise-then-deny inconsistency that was the reason to filter
	 * list_collections in the first place, just on a bigger, harder-to-spot
	 * surface (every read tool's description, not one discovery tool's
	 * output). Deliberately a caller-supplied closure rather than injecting
	 * PersonaContext directly into this class: PersonaContext already
	 * depends on McpSchemaResolver (for its own canReadCollection() public-
	 * collection carve-out) — the reverse dependency would invert that and
	 * risk a container cycle. The three content tools (which already inject
	 * PersonaContext for their own handler-level checks) pass `fn
	 * (CollectionData $c): bool => $this->personaContext->
	 * canReadCollection($c->id, $c)` — see their buildDescription().
	 * Metadata-only concern either way (ids + field names, never object
	 * values), but the inconsistency itself is worth closing.
	 */
	public function renderCatalog(McpPersona $persona, int $cap = self::DEFAULT_CATALOG_CAP, ?\Closure $isReadable = null): string
	{
		$visible = array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $collection): bool => $this->isAccessibleTo($collection, $persona->value)
				&& (!$isReadable instanceof \Closure || $isReadable($collection)),
		);

		if ($visible === []) {
			return '';
		}

		// Stable order so identical sites generate identical tool descriptions.
		usort($visible, static fn (CollectionData $a, CollectionData $b): int => strcmp($a->id, $b->id));

		$overflow = max(0, count($visible) - $cap);
		$shown    = array_slice($visible, 0, $cap);

		$lines = ['Available collections and filterable properties:'];
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
		foreach ($this->describeProperties($collection) as $property) {
			// Catalog scope: only actionable properties appear in tool
			// descriptions. Non-indexed properties live on objects but
			// aren't query targets — they're documented in describe_collection
			// output instead, where the agent can see "filterable: false".
			if (!$property['filterable'] && !$property['sortable']) {
				continue;
			}

			$attrs = [$property['type']];
			if ($property['sortable']) {
				$attrs[] = 'sortable';
			}

			$parts[] = $property['name'] . ' (' . implode(', ', $attrs) . ')';
		}

		return $parts === [] ? '(no filterable properties)' : implode(', ', $parts);
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

		return $this->isPropertyArrayExposed($property);
	}

	/**
	 * Effective `mcp.expose` decision for a property.
	 *
	 * Default: exposed. Two paths to non-exposure:
	 *   1. Explicit operator opt-out — `mcp.expose: false` set on the property.
	 *   2. Sensitive-field default — `field` is in {@see SchemaData::SENSITIVE_FIELD_TYPES}
	 *      (password / secret) and the operator has NOT explicitly opted IN with
	 *      `mcp.expose: true`. Catches the common case (hashed passwords, API
	 *      keys, OAuth tokens) without requiring per-schema opt-out.
	 *
	 * Explicit beats default: an operator who really wants a secret field
	 * surfaced (rare, but supported) sets `mcp.expose: true` and wins.
	 *
	 * @param array<string,mixed> $property
	 */
	private function isPropertyArrayExposed(array $property): bool
	{
		$mcp = is_array($property['mcp'] ?? null) ? $property['mcp'] : [];

		if (array_key_exists('expose', $mcp)) {
			return $mcp['expose'] !== false;
		}

		$fieldType = (string)($property['field'] ?? '');

		return !in_array($fieldType, SchemaData::SENSITIVE_FIELD_TYPES, true);
	}

	/**
	 * Filterability is derived purely from the schema's index + field type —
	 * operators steer filtering by editing the schema's `index` list (the real
	 * lever). Per-property `mcp.filterable` keys are intentionally ignored;
	 * any persisted values are inert.
	 */
	private function resolveFilterable(string $fieldType): bool
	{
		return ObjectFilter::isFilterableType($fieldType);
	}

	/**
	 * Sortability is derived purely from the field type — see resolveFilterable.
	 * Per-property `mcp.sortable` keys are intentionally ignored.
	 */
	private function resolveSortable(string $fieldType): bool
	{
		return CollectionSorter::isSortableType($fieldType);
	}
}

<?php

namespace TotalCMS\Domain\Schema\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

readonly class SchemaFetcher
{
	private LoggerInterface $logger;

	public function __construct(
		private SchemaRepository $storage,
		private CollectionFetcher $collectionFetcher,
		private CacheManager $cacheManager,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::App);
	}

	/**
	 * Fetch a schema with inheritance resolved (flattened).
	 * Returns the schema with all inherited properties merged in.
	 */
	public function fetchSchema(string $id): SchemaData
	{
		$schema = $this->storage->getSchema($id);

		if ($schema->inheritFrom === []) {
			return $schema;
		}

		return $this->resolveInheritance($schema);
	}

	/**
	 * Fetch a raw schema without resolving inheritance.
	 * Returns only the schema's own properties, without merging inherited properties.
	 */
	public function fetchRawSchema(string $id): SchemaData
	{
		return $this->storage->getSchema($id);
	}

	/**
	 * Whether a schema is `$target` or inherits it, directly or through any
	 * number of parents. False for a schema that cannot be read.
	 */
	public function isOrInherits(string $schemaId, string $target): bool
	{
		return $this->inheritsAny($schemaId, [$target]);
	}

	/**
	 * Whether a schema is, or inherits at any depth, one of `$targets`.
	 * False for a schema that cannot be read.
	 *
	 * @param list<string> $targets Schema ids
	 */
	public function inheritsAny(string $schemaId, array $targets): bool
	{
		if (in_array($schemaId, $targets, true)) {
			return true;
		}

		try {
			$schema  = $this->fetchRawSchema($schemaId);
			$seen    = [$schemaId => true];
			$skipped = [];
			$parents = $this->collectParents($schema, 1, $seen, $skipped);
		} catch (\Throwable) {
			return false;
		}

		// Match on the ids named in inheritFrom, so a parent that cannot be
		// read still counts when it is the target itself.
		foreach ([$schema, ...$parents] as $ancestor) {
			if (array_intersect($targets, $ancestor->inheritFrom) !== []) {
				return true;
			}
		}

		return false;
	}

	public function schemaExists(string $id): bool
	{
		return $this->storage->schemaExists($id);
	}

	/**
	 * Whether the schema's stored file is present but cannot be decoded — the
	 * case schemaExists() deliberately reports as absent.
	 */
	public function schemaIsUnreadable(string $id): bool
	{
		return $this->storage->schemaIsUnreadable($id);
	}

	/**
	 * Check if a schema ID is a custom (non-reserved, non-extension) schema.
	 */
	public function isCustomSchema(string $schemaId): bool
	{
		return !in_array($schemaId, $this->storage->reservedSchemasIds(), true);
	}

	/**
	 * Fetch a schema for a collection with inheritance resolved (flattened).
	 */
	public function fetchSchemaForCollection(string $collection): SchemaData
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->fetchSchema($collectionData->schema);
	}

	/**
	 * Fetch a raw schema for a collection without resolving inheritance.
	 */
	public function fetchRawSchemaForCollection(string $collection): SchemaData
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->fetchRawSchema($collectionData->schema);
	}

	/**
	 * Extract schema ID from a schema reference URL or return as-is if already an ID.
	 * Handles URLs like "https://www.totalcms.co/schemas/custom/features.json" → "features".
	 */
	public static function extractSchemaId(string $schemaRef): string
	{
		$path = parse_url($schemaRef, PHP_URL_PATH);
		if ($path) {
			return basename($path, '.json');
		}

		return $schemaRef;
	}

	/**
	 * Resolve schema inheritance by merging properties, required, and index arrays.
	 * Parents are followed at any depth (up to SchemaData::MAX_INHERIT_DEPTH), depth first,
	 * and the nearest definition of a property wins.
	 */
	private function resolveInheritance(SchemaData $schema): SchemaData
	{
		// Check cache first
		$cacheKey = SchemaData::flattenedCacheKey($schema->id);
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			try {
				$flattened = new SchemaData();
				foreach ($cached as $key => $value) {
					if (property_exists($flattened, $key)) {
						$flattened->$key = $value;
					}
				}

				return $flattened;
			} catch (\Exception) {
				// Invalid cache, continue to rebuild
			}
		}

		// Build flattened schema
		$flattened              = new SchemaData();
		$flattened->id          = $schema->id;
		$flattened->formgrid    = $schema->formgrid;
		$flattened->description = $schema->description;
		$flattened->category    = $schema->category;
		$flattened->inheritFrom = $schema->inheritFrom;

		// Start with the schema's own properties (they take precedence)
		$mergedProperties = $schema->properties;
		$mergedRequired   = $schema->required;
		$mergedIndex      = $schema->index;

		// Track which property names have been added (first wins)
		$addedProperties = array_keys($mergedProperties);

		// Merge every ancestor in first-wins order
		$seen    = [$schema->id => true];
		$skipped = [];
		$parents = $this->collectParents($schema, 1, $seen, $skipped);

		if ($skipped !== []) {
			$this->logger->warning(
				"Schema '{$schema->id}' inherits deeper than " . SchemaData::MAX_INHERIT_DEPTH . ' levels; skipped: ' . implode(', ', $skipped),
			);
		}

		foreach ($parents as $parentSchema) {
			// Merge properties (skip conflicts - first wins)
			foreach ($parentSchema->properties as $propName => $propDef) {
				if (!in_array($propName, $addedProperties, true)) {
					$mergedProperties[$propName] = $propDef;
					$addedProperties[]           = $propName;
				}
			}

			// Merge required array (union)
			$mergedRequired = array_unique(array_merge($mergedRequired, $parentSchema->required));

			// Merge index array (union, preserve order)
			$mergedIndex = array_unique(array_merge($mergedIndex, $parentSchema->index));
		}

		$flattened->properties = $mergedProperties;
		$flattened->required   = array_values($mergedRequired); // Re-index
		$flattened->index      = array_values($mergedIndex);    // Re-index
		$flattened->formgrid   = $this->joinedLayout($schema);

		// Cache the flattened schema (CacheManager::TTL_FLATTENED_SCHEMA, 4 hours)
		$cacheData = [
			'id'          => $flattened->id,
			'formgrid'    => $flattened->formgrid,
			'description' => $flattened->description,
			'category'    => $flattened->category,
			'properties'  => $flattened->properties,
			'required'    => $flattened->required,
			'index'       => $flattened->index,
			'inheritFrom' => $flattened->inheritFrom,
		];
		$this->cacheManager->storeComputedData($cacheKey, $cacheData, CacheManager::TTL_FLATTENED_SCHEMA);

		return $flattened;
	}

	/**
	 * The form layout of a flattened schema. A schema's own layout is used as
	 * written. Without one, the layouts of its parents are brought to one width
	 * and joined in order. A field no layout places is added by the form, after
	 * the layout, as a full-width row. No layout anywhere stays empty.
	 */
	private function joinedLayout(SchemaData $schema): string
	{
		if (trim($schema->formgrid) !== '') {
			return $schema->formgrid;
		}

		$seen = [$schema->id => true];

		return implode("\n", $this->scaledLayouts($this->parentLayouts($schema, 1, $seen)));
	}

	/**
	 * The layouts of a schema's direct parents, in order. A parent with no
	 * layout of its own contributes its own parents' layouts, by the same rule.
	 * Follows the depth cap and skips schemas already seen, as the ancestor
	 * walk does.
	 *
	 * @param array<string,true> $seen Ids already visited, shared across the walk
	 *
	 * @return list<string>
	 */
	private function parentLayouts(SchemaData $schema, int $depth, array &$seen): array
	{
		$layouts = [];

		foreach ($schema->inheritFrom as $parentId) {
			if (isset($seen[$parentId]) || $depth > SchemaData::MAX_INHERIT_DEPTH) {
				continue;
			}

			try {
				$parent = $this->fetchRawSchema($parentId);
			} catch (\Exception) {
				continue;
			}

			$seen[$parentId] = true;
			if (trim($parent->formgrid) !== '') {
				$layouts[] = $parent->formgrid;
				continue;
			}
			array_push($layouts, ...$this->parentLayouts($parent, $depth + 1, $seen));
		}

		return $layouts;
	}

	/**
	 * Parent layouts brought to one width, so every row of the joined layout
	 * has the same number of cells (grid-template-areas needs that, or the
	 * admin drops the layout). The width is the least common multiple of the
	 * layouts' widths, and every cell of an ordinary row is repeated to reach
	 * it: `a b` at width 2 becomes `a a a b b b` at width 6. Marker rows are
	 * left alone. The layouts are joined unscaled when they already agree, when
	 * one layout's own rows differ in width, or when the common width would be
	 * more than 12 columns.
	 *
	 * @param list<string> $layouts
	 *
	 * @return list<string>
	 */
	private function scaledLayouts(array $layouts): array
	{
		$widths = array_map($this->layoutWidth(...), $layouts);
		$sized  = array_values(array_unique(array_filter($widths)));
		if (in_array(null, $widths, true) || count($sized) < 2) {
			return $layouts;
		}

		$common = 1;
		foreach ($sized as $width) {
			$multiple = $common;
			while ($multiple % $width !== 0) {
				$multiple += $common;
			}
			$common = $multiple;
		}
		if ($common > 12) {
			return $layouts;
		}

		foreach ($layouts as $i => $layout) {
			$times = intdiv($common, max(1, (int) $widths[$i]));
			$rows  = [];
			foreach (preg_split('/\R/', $layout) ?: [] as $row) {
				$cells  = $this->rowCells($row);
				$rows[] = $cells === null
					? $row
					: implode(' ', array_merge(...array_map(fn (string $cell): array => array_fill(0, $times, $cell), $cells)));
			}
			$layouts[$i] = implode("\n", $rows);
		}

		return $layouts;
	}

	/**
	 * The number of cells in each ordinary row of a layout: 0 when it has
	 * none, null when its rows differ.
	 */
	private function layoutWidth(string $layout): ?int
	{
		$widths = [];
		foreach (preg_split('/\R/', $layout) ?: [] as $row) {
			$cells = $this->rowCells($row);
			if ($cells !== null) {
				$widths[count($cells)] = true;
			}
		}

		return match (count($widths)) {
			0       => 0,
			1       => (int) array_key_first($widths),
			default => null,
		};
	}

	/**
	 * The cells of an ordinary layout row, or null for a blank line or a
	 * marker: a section heading or divider (`---`), a fieldset (`[[`, `]]`) or
	 * an accordion (`>>`, `<<`). The text after a marker is a title, not cells.
	 *
	 * @return list<string>|null
	 */
	private function rowCells(string $row): ?array
	{
		$row = trim($row);
		if ($row === '') {
			return null;
		}

		foreach (['---', '[[', ']]', '>>', '<<'] as $marker) {
			if (str_starts_with($row, $marker)) {
				return null;
			}
		}

		return preg_split('/\s+/', $row) ?: [];
	}

	/**
	 * The raw ancestors of a schema in first-wins order: each parent, then that
	 * parent's own ancestors, before the next parent. A schema already seen
	 * (a cycle, or a shared grandparent) is skipped; a parent that cannot be
	 * read is skipped along with its ancestors.
	 *
	 * @param array<string,true> $seen    Ids already visited, shared across the walk
	 * @param list<string>       $skipped Ids left out for being deeper than SchemaData::MAX_INHERIT_DEPTH
	 *
	 * @return list<SchemaData>
	 */
	private function collectParents(SchemaData $schema, int $depth, array &$seen, array &$skipped): array
	{
		$parents = [];

		foreach ($schema->inheritFrom as $parentId) {
			if (isset($seen[$parentId])) {
				continue;
			}

			if ($depth > SchemaData::MAX_INHERIT_DEPTH) {
				$skipped[] = $parentId;
				continue;
			}

			try {
				$parent = $this->fetchRawSchema($parentId);
			} catch (\Exception) {
				// If inherited schema doesn't exist, skip it
				continue;
			}

			$seen[$parentId] = true;
			$parents[]       = $parent;
			array_push($parents, ...$this->collectParents($parent, $depth + 1, $seen, $skipped));
		}

		return $parents;
	}
}

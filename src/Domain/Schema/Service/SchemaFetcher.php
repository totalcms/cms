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
	/** How many levels of parents inheritance follows. */
	private const MAX_INHERIT_DEPTH = 5;

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
	 * Parents are followed at any depth (up to MAX_INHERIT_DEPTH), depth first,
	 * and the nearest definition of a property wins.
	 */
	private function resolveInheritance(SchemaData $schema): SchemaData
	{
		// Check cache first
		$cacheKey = "schema_flattened:{$schema->id}";
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
				"Schema '{$schema->id}' inherits deeper than " . self::MAX_INHERIT_DEPTH . ' levels; skipped: ' . implode(', ', $skipped),
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
		$flattened->formgrid   = $this->joinedLayout($schema, array_keys($mergedProperties));

		// Cache the flattened schema for 30 minutes
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
	 * written. Without one, the layouts of its parents are joined in order and
	 * every property the join does not place gets a row of its own at the end.
	 * No layout anywhere stays empty.
	 *
	 * @param list<int|string> $propertyNames Property names of the flattened schema, in order
	 */
	private function joinedLayout(SchemaData $schema, array $propertyNames): string
	{
		if (trim($schema->formgrid) !== '') {
			return $schema->formgrid;
		}

		$seen   = [$schema->id => true];
		$joined = $this->parentLayouts($schema, 1, $seen);

		if ($joined === '') {
			return '';
		}

		$placed = $this->placedProperties($joined);
		foreach ($propertyNames as $name) {
			if (!isset($placed[(string) $name])) {
				$joined .= "\n" . $name;
			}
		}

		return $joined;
	}

	/**
	 * The layouts of a schema's direct parents joined with a newline. A parent
	 * with no layout of its own contributes its own parents' layouts, by the
	 * same rule. Follows the depth cap and skips schemas already seen, as the
	 * ancestor walk does.
	 *
	 * @param array<string,true> $seen Ids already visited, shared across the walk
	 */
	private function parentLayouts(SchemaData $schema, int $depth, array &$seen): string
	{
		$layouts = [];

		foreach ($schema->inheritFrom as $parentId) {
			if (isset($seen[$parentId]) || $depth > self::MAX_INHERIT_DEPTH) {
				continue;
			}

			try {
				$parent = $this->fetchRawSchema($parentId);
			} catch (\Exception) {
				continue;
			}

			$seen[$parentId] = true;
			$layout          = trim($parent->formgrid) !== ''
				? $parent->formgrid
				: $this->parentLayouts($parent, $depth + 1, $seen);

			if ($layout !== '') {
				$layouts[] = $layout;
			}
		}

		return implode("\n", $layouts);
	}

	/**
	 * The property names a layout places: every whole cell of a row that is not
	 * a marker: a section heading or divider (`---`), a fieldset (`[[`, `]]`)
	 * or an accordion (`>>`, `<<`). The text after a marker is a title, not cells.
	 *
	 * @return array<string,true>
	 */
	private function placedProperties(string $layout): array
	{
		$placed = [];

		foreach (preg_split('/\R/', $layout) ?: [] as $row) {
			$row = trim($row);
			if ($this->isMarkerRow($row)) {
				continue;
			}

			foreach (preg_split('/\s+/', $row, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $cell) {
				$placed[$cell] = true;
			}
		}

		return $placed;
	}

	/**
	 * Whether a layout row is a marker rather than a row of cells.
	 */
	private function isMarkerRow(string $row): bool
	{
		foreach (['---', '[[', ']]', '>>', '<<'] as $marker) {
			if (str_starts_with($row, $marker)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The raw ancestors of a schema in first-wins order: each parent, then that
	 * parent's own ancestors, before the next parent. A schema already seen
	 * (a cycle, or a shared grandparent) is skipped; a parent that cannot be
	 * read is skipped along with its ancestors.
	 *
	 * @param array<string,true> $seen    Ids already visited, shared across the walk
	 * @param list<string>       $skipped Ids left out for being deeper than MAX_INHERIT_DEPTH
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

			if ($depth > self::MAX_INHERIT_DEPTH) {
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

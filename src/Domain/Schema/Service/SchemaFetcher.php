<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

readonly class SchemaFetcher
{
	public function __construct(
		private SchemaRepository $storage,
		private CollectionRepository $collectionRepository,
		private CacheManager $cacheManager,
	) {
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

	public function schemaExists(string $id): bool
	{
		return $this->storage->schemaExists($id);
	}

	/**
	 * Fetch a schema for a collection with inheritance resolved (flattened).
	 */
	public function fetchSchemaForCollection(string $collection): SchemaData
	{
		$collectionData = $this->collectionRepository->fetchCollection($collection);

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
		$collectionData = $this->collectionRepository->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->fetchRawSchema($collectionData->schema);
	}

	/**
	 * Extract schema ID from deckref URL or return as-is if already an ID.
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
	 * Uses first-wins conflict resolution and only does single-level inheritance.
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

		// Merge each inherited schema in order
		foreach ($schema->inheritFrom as $inheritId) {
			try {
				// Get parent schema without flattening (only one level)
				$parentSchema = $this->storage->getSchema($inheritId);

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
			} catch (\Exception) {
				// If inherited schema doesn't exist, skip it
				continue;
			}
		}

		$flattened->properties = $mergedProperties;
		$flattened->required   = array_values($mergedRequired); // Re-index
		$flattened->index      = array_values($mergedIndex);    // Re-index

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
		$this->cacheManager->storeComputedData($cacheKey, $cacheData, 1800);

		return $flattened;
	}
}

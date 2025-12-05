<?php

namespace TotalCMS\Domain\Schema\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Support\Config;

/**
 * Repository.
 */
class SchemaRepository extends StorageRepository
{
	public const DEFAULT_SCHEMA_DIR = __DIR__ . '/../../../../resources/schemas/';
	private const CUSTOM_SCHEMA_DIR = '.schemas/';

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 */
	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly SchemaFactory $factory,
		private readonly CacheManager $cacheManager,
		private readonly Config $config,
	) {
		parent::__construct($filesystem);
	}

	public function getCustomSchemaDir(): string
	{
		// Return the absolute path to the custom schemas directory
		// Since the filesystem adapter is rooted at tcms-data/, we can construct the full path
		return $this->config->datadir . '/' . self::CUSTOM_SCHEMA_DIR;
	}

	/**
	 * List custom Schemas.
	 *
	 * @return array<SchemaData>
	 */
	public function listCustomSchemas(): array
	{
		$files = $this->filesystem->listFiles(self::CUSTOM_SCHEMA_DIR);

		$schemas = [];

		foreach ($files as $file) {
			$id     = basename($file, self::FILE_EXT);
			$schema = $this->fetchCustomSchema($id);
			if ($schema instanceof SchemaData) {
				$schemas[] = $schema;
			}
		}

		return $schemas;
	}

	/**
	 * List reserved Schemas.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @return array<SchemaData>
	 */
	public function listReservedSchemas(): array
	{
		// Try cache first (reserved schemas never change during runtime)
		$cacheKey = 'reserved_schemas_list';
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			$schemas = $this->hydrateSchemasFromCache($cached);
			if ($schemas !== []) {
				return $schemas;
			}
		}

		// Cache miss - load all reserved schemas
		$ids     = $this->reservedSchemasIds();
		$schemas = [];

		foreach ($ids as $id) {
			$schema = $this->fetchDefaultSchema($id);
			if ($schema instanceof SchemaData) {
				$schemas[] = $schema;
			}
		}

		// Cache the schemas as arrays for 1 hour (reserved schemas never change)
		if ($schemas === []) {
			// Clear cache if no schemas to prevent serving stale data
			$this->cacheManager->clearComputedData($cacheKey);
		} else {
			// Cache non-empty schemas
			$schemasArray = array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
			$this->cacheManager->storeComputedData($cacheKey, $schemasArray, CacheManager::TTL_RESERVED_SCHEMAS);
		}

		return $schemas;
	}

	/**
	 * List reserved Schema IDs.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @return array<string>
	 */
	public function reservedSchemasIds(): array
	{
		return SchemaData::RESERVED_SCHEMAS;
	}

	/**
	 * fetch a schema for one of the default schema types.
	 */
	public function fetchDefaultSchema(string $id): ?SchemaData
	{
		// Try cache first (Redis preferred, long TTL since default schemas never change)
		$cacheKey = "schema:{$id}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			try {
				return $this->factory->generateSchema($cached);
			} catch (\Exception) {
				// Cache contains invalid data, fall through to filesystem
			}
		}

		// Cache miss - load from filesystem
		$schemaFile = self::DEFAULT_SCHEMA_DIR . $id . self::FILE_EXT;
		$contents   = null;

		// Cannot use flysystem here because
		// the file resides outside of the datadir
		if (file_exists($schemaFile)) {
			$contents = file_get_contents($schemaFile);
		}

		if (in_array($contents, ['', null, false], true)) {
			return null;
		}

		$schema = $this->factory->generateSchemaFromJson($contents);

		// Cache default schema for 1 hour (they never change during runtime)
		$this->cacheManager->storeComputedData($cacheKey, $schema->toArray(), CacheManager::TTL_CUSTOM_SCHEMA);

		return $schema;
	}

	/**
	 * fetch a schema for a custom schema type.
	 */
	public function fetchCustomSchema(string $id): ?SchemaData
	{
		// Try cache first (Redis preferred, medium TTL since custom schemas change rarely)
		$cacheKey = "schema:{$id}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			try {
				return $this->factory->generateSchema($cached);
			} catch (\Exception) {
				// Cache contains invalid data, fall through to filesystem
			}
		}

		// Cache miss - load from filesystem
		$schemaFile = self::CUSTOM_SCHEMA_DIR . $id . self::FILE_EXT;
		$schema     = $this->fetchAndDeserialize($schemaFile, SchemaData::class);

		// Cache custom schema for 30 minutes (changes rarely but can be modified by users)
		if ($schema !== null) {
			$this->cacheManager->storeComputedData($cacheKey, $schema->toArray(), 1800);
		}

		return $schema;
	}

	/**
	 * fetch a schema for one of the default schema types.
	 */
	public function getSchema(string $id): SchemaData
	{
		$schema = $this->fetchDefaultSchema($id);

		if (!$schema instanceof SchemaData) {
			$schema = $this->fetchCustomSchema($id);
		}

		if (!$schema instanceof SchemaData) {
			throw new \DomainException(sprintf('Schema type does not exist: %s', $id));
		}

		return $schema;
	}

	public function schemaExists(string $id): bool
	{
		$schema = $this->fetchDefaultSchema($id);

		if (!$schema instanceof SchemaData) {
			$schema = $this->fetchCustomSchema($id);
		}

		return $schema instanceof SchemaData;
	}

	/**
	 * save a collection schema.
	 */
	public function saveSchema(SchemaData $schema): void
	{
		$schemaFile = self::CUSTOM_SCHEMA_DIR . $schema->id . self::FILE_EXT;
		$schemaJSON = $schema->toJson();

		if ($schemaJSON === '') {
			throw new \DomainException(sprintf('Failed to encode schema for type: %s', $schema->id));
		}

		$this->filesystem->write($schemaFile, $schemaJSON);

		// Invalidate cached custom schema when saved
		$this->invalidateCustomSchemaCache($schema->id);
	}

	public function deleteSchema(string $id): bool
	{
		$schemaFile = self::CUSTOM_SCHEMA_DIR . $id . self::FILE_EXT;

		$result = $this->filesystem->delete($schemaFile);

		// Invalidate cached custom schema when deleted
		if ($result) {
			$this->invalidateCustomSchemaCache($id);
		}

		return $result;
	}

	/**
	 * Invalidate schema-related caches for a custom schema.
	 */
	private function invalidateCustomSchemaCache(string $id): void
	{
		// Clear custom schema cache
		$this->cacheManager->clearComputedData("schema:{$id}");

		// Also clear flattened cache if this schema is flattened
		$this->cacheManager->clearComputedData("schema_flattened:{$id}");

		// Clear caches for any schemas that inherit from this one
		$this->invalidateInheritedSchemaCaches($id);
	}

	/**
	 * Convert cached schema arrays back to SchemaData objects.
	 *
	 * @param array<array<string,mixed>> $cachedSchemas
	 *
	 * @return array<SchemaData>
	 */
	private function hydrateSchemasFromCache(array $cachedSchemas): array
	{
		$schemas = [];
		foreach ($cachedSchemas as $schemaArray) {
			try {
				$schemas[] = $this->factory->generateSchema($schemaArray);
			} catch (\Exception) {
				// Skip invalid cached schema, will be refreshed from source
			}
		}

		return $schemas;
	}

	/**
	 * Find all schemas that inherit from the given schema ID.
	 *
	 * @return array<string> Array of schema IDs that inherit from the given schema
	 */
	public function findInheritingSchemas(string $schemaId): array
	{
		$inheritingSchemas = [];

		// Check custom schemas
		$customSchemas = $this->listCustomSchemas();
		foreach ($customSchemas as $schema) {
			if (in_array($schemaId, $schema->inheritFrom, true)) {
				$inheritingSchemas[] = $schema->id;
			}
		}

		// Note: Reserved schemas cannot be deleted, so we don't need to check them

		return $inheritingSchemas;
	}

	/**
	 * Check if a schema is inherited by any other schemas.
	 */
	public function isSchemaInherited(string $schemaId): bool
	{
		return $this->findInheritingSchemas($schemaId) !== [];
	}

	/**
	 * Invalidate flattened schema caches for all schemas that inherit from the given schema.
	 * This should be called when a schema is updated or deleted.
	 */
	public function invalidateInheritedSchemaCaches(string $schemaId): void
	{
		$inheritingSchemas = $this->findInheritingSchemas($schemaId);

		foreach ($inheritingSchemas as $inheritingSchemaId) {
			$this->cacheManager->clearComputedData("schema_flattened:{$inheritingSchemaId}");
		}
	}
}

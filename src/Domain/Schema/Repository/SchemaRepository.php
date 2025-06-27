<?php

namespace TotalCMS\Domain\Schema\Repository;

use Dynamics\Schema;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository.
 */
final class SchemaRepository extends StorageRepository
{
	public const DEFAULT_SCHEMA_DIR = __DIR__ . '/../../../../resources/schemas/';
	private const CUSTOM_SCHEMA_DIR = '.schemas/';

	private SchemaFactory $factory;
	private CacheManager $cacheManager;

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 * @param SchemaFactory $factory
	 * @param CacheManager $cacheManager
	 */
	public function __construct(StorageAdapterInterface $filesystem, SchemaFactory $factory, CacheManager $cacheManager)
	{
		parent::__construct($filesystem);
		$this->factory      = $factory;
		$this->cacheManager = $cacheManager;
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
			if ($schema !== null) {
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
			if (!empty($schemas)) {
				return $schemas;
			}
		}

		// Cache miss - load all reserved schemas
		$ids     = $this->reservedSchemasIds();
		$schemas = [];

		foreach ($ids as $id) {
			$schema = $this->fetchDefaultSchema($id);
			if ($schema !== null) {
				$schemas[] = $schema;
			}
		}

		// Cache the schemas as arrays for 1 hour (reserved schemas never change)
		if (empty($schemas)) {
			// Clear cache if no schemas to prevent serving stale data
			$this->cacheManager->clearComputedData($cacheKey);
		} else {
			// Cache non-empty schemas
			$schemasArray = array_map(fn ($schema) => $schema->toArray(), $schemas);
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
		// Try cache first (reserved schemas never change during runtime)
		$cacheKey = 'reserved_schema_ids';
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			return $cached;
		}

		// Cache miss - expensive glob() operation
		$files = glob(self::DEFAULT_SCHEMA_DIR . '*' . self::FILE_EXT);

		if ($files === false) {
			throw new \RuntimeException('Failed to list reserved schemas');
		}

		$ids = array_map(function (string $file) {
			return basename($file, self::FILE_EXT);
		}, $files);

		$filteredIds = array_filter($ids, function (string $id) {
			// Exclude the schema and collection schemas
			return $id !== 'schema' && $id !== 'collection';
		});

		// Cache for 1 hour (reserved schema IDs never change)
		if (empty($filteredIds)) {
			// Clear cache if no filtered IDs to prevent serving stale data
			$this->cacheManager->clearComputedData($cacheKey);
		} else {
			// Cache non-empty filtered IDs
			$this->cacheManager->storeComputedData($cacheKey, $filteredIds, CacheManager::TTL_RESERVED_SCHEMA_IDS);
		}

		return $filteredIds;
	}

	/**
	 * fetch a schema for one of the default schema types.
	 *
	 * @param string $id
	 *
	 * @return ?SchemaData
	 */
	public function fetchDefaultSchema(string $id): ?SchemaData
	{
		// Try cache first (Redis preferred, long TTL since default schemas never change)
		$cacheKey = "schema:{$id}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			try {
				return $this->factory->generateSchema($cached);
			} catch (\Exception $e) {
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

		if (empty($contents)) {
			return null;
		}

		$schema = $this->factory->generateSchemaFromJson($contents);

		// Cache default schema for 1 hour (they never change during runtime)
		$this->cacheManager->storeComputedData($cacheKey, $schema->toArray(), CacheManager::TTL_CUSTOM_SCHEMA);

		return $schema;
	}

	/**
	 * fetch a schema for a custom schema type.
	 *
	 * @param string $id
	 *
	 * @return ?SchemaData
	 */
	public function fetchCustomSchema(string $id): ?SchemaData
	{
		// Try cache first (Redis preferred, medium TTL since custom schemas change rarely)
		$cacheKey = "schema:{$id}";
		$cached   = $this->cacheManager->getComputedData($cacheKey);

		if ($cached !== null && is_array($cached)) {
			try {
				return $this->factory->generateSchema($cached);
			} catch (\Exception $e) {
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
	 *
	 * @param string $id
	 *
	 * @return SchemaData
	 */
	public function getSchema(string $id): SchemaData
	{
		$schema = $this->fetchDefaultSchema($id);

		if ($schema === null) {
			$schema = $this->fetchCustomSchema($id);
		}

		if ($schema === null) {
			throw new \DomainException(sprintf('Schema type does not exist: %s', $id));
		}

		return $schema;
	}

	public function schemaExists(string $id): bool
	{
		$schema = $this->fetchDefaultSchema($id);

		if ($schema === null) {
			$schema = $this->fetchCustomSchema($id);
		}

		if ($schema === null) {
			return false;
		}

		return true;
	}

	/**
	 * save a collection schema.
	 *
	 * @param SchemaData $schema
	 *
	 * @return void
	 */
	public function saveSchema(SchemaData $schema): void
	{
		$schemaFile = self::CUSTOM_SCHEMA_DIR . $schema->id . self::FILE_EXT;
		$schemaJSON = $schema->toJson();

		if (empty($schemaJSON)) {
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
			} catch (\Exception $e) {
				// Skip invalid cached schema, will be refreshed from source
			}
		}

		return $schemas;
	}
}

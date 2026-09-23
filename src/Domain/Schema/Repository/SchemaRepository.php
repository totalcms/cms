<?php

namespace TotalCMS\Domain\Schema\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Storage\Exception\CorruptedStorageFileException;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Repository.
 */
class SchemaRepository extends StorageRepository
{
	public static function defaultSchemaDir(): string
	{
		return PathResolver::packageRoot() . '/resources/schemas/';
	}
	private const CUSTOM_SCHEMA_DIR = '.schemas/';

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 */
	/** @var list<string> Absolute paths to extension schema directories */
	private array $extensionSchemaDirs = [];

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly SchemaFactory $factory,
		private readonly CacheManager $cacheManager,
		private readonly Config $config,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Register an extension's schema directory.
	 * Schemas in this directory are read-only and managed by the extension.
	 */
	public function registerExtensionSchemaDir(string $absolutePath): void
	{
		if (is_dir($absolutePath)) {
			$this->extensionSchemaDirs[] = $absolutePath;
		}
	}

	public function getCustomSchemaDir(): string
	{
		// Return the absolute path to the custom schemas directory
		// Since the filesystem adapter is rooted at tcms-data/, we can construct the full path
		return PathUtils::absolutePath($this->config->datadir, self::CUSTOM_SCHEMA_DIR);
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
	 * @return array<string>
	 */
	public function reservedSchemasIds(): array
	{
		$extensionIds = array_map(
			fn (SchemaData $s): string => $s->id,
			$this->listExtensionSchemas(),
		);

		return array_merge(SchemaData::RESERVED_SCHEMAS, $extensionIds);
	}

	/**
	 * fetch a schema for one of the default schema types.
	 */
	public function fetchDefaultSchema(string $id): ?SchemaData
	{
		$cached = $this->cached($id);
		if ($cached instanceof SchemaData) {
			return $cached;
		}

		$schemaFile = self::defaultSchemaDir() . $id . self::FILE_EXT;
		$contents   = null;

		// Cannot use flysystem here because
		// the file resides outside of the datadir
		if (file_exists($schemaFile)) {
			$contents = file_get_contents($schemaFile);
		}

		if (in_array($contents, ['', null, false], true)) {
			return null;
		}

		return $this->remember($id, $this->factory->generateSchemaFromJson($contents));
	}

	/**
	 * fetch a schema for a custom schema type.
	 */
	public function fetchCustomSchema(string $id): ?SchemaData
	{
		$cached = $this->cached($id);
		if ($cached instanceof SchemaData) {
			return $cached;
		}

		$schema = $this->fetchAndDeserialize(self::CUSTOM_SCHEMA_DIR . $id . self::FILE_EXT, SchemaData::class);

		return $schema instanceof SchemaData ? $this->remember($id, $schema) : null;
	}

	/**
	 * The cached copy of a default or custom schema, or null on a miss. A
	 * cache entry that no longer builds a schema reads as a miss so the file
	 * is consulted.
	 */
	private function cached(string $id): ?SchemaData
	{
		$cached = $this->cacheManager->getComputedData("schema:{$id}");
		if (!is_array($cached)) {
			return null;
		}

		try {
			return $this->factory->generateSchema($cached);
		} catch (\Exception) {
			return null;
		}
	}

	/** Cache a freshly loaded schema. Default schemas never change at runtime; custom ones rarely. */
	private function remember(string $id, SchemaData $schema): SchemaData
	{
		$this->cacheManager->storeComputedData("schema:{$id}", $schema->toArray(), CacheManager::TTL_CUSTOM_SCHEMA);

		return $schema;
	}

	/**
	 * Fetch a schema from extension directories (read-only, managed by extensions).
	 */
	public function fetchExtensionSchema(string $id): ?SchemaData
	{
		foreach ($this->extensionSchemaDirs as $dir) {
			$schema = $this->readExtensionSchema($dir . '/' . $id . '.json');
			if ($schema instanceof SchemaData) {
				return $schema;
			}
		}

		return null;
	}

	/** An extension schema file, or null when it is missing, unreadable or invalid. */
	private function readExtensionSchema(string $file): ?SchemaData
	{
		if (!is_file($file)) {
			return null;
		}

		$json = file_get_contents($file);
		$data = $json === false ? null : json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}

		try {
			return $this->factory->generateSchema($data);
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * List all schemas provided by extensions.
	 *
	 * @return array<SchemaData>
	 */
	public function listExtensionSchemas(): array
	{
		$schemas = [];

		foreach ($this->extensionSchemaDirs as $dir) {
			$files = glob($dir . '/*.json');
			if ($files === false) {
				continue;
			}
			foreach ($files as $file) {
				$schema = $this->readExtensionSchema($file);
				if ($schema instanceof SchemaData) {
					$schemas[] = $schema;
				}
			}
		}

		return $schemas;
	}

	/**
	 * fetch a schema for one of the default schema types.
	 */
	public function getSchema(string $id): SchemaData
	{
		return $this->resolve($id) ?? throw new \DomainException(sprintf('Schema type does not exist: %s', $id));
	}

	/** The resolution chain: default → extension → custom. */
	private function resolve(string $id): ?SchemaData
	{
		return $this->fetchDefaultSchema($id)
			?? $this->fetchExtensionSchema($id)
			?? $this->fetchCustomSchema($id);
	}

	/**
	 * A predicate must answer. One unparseable schema file used to throw out of
	 * here and take down every caller — nine of them, including the admin
	 * schema page and `schema:lint`, the very tool for finding bad schemas. A
	 * corrupt file is reported as absent so the rest of the install keeps
	 * working; {@see schemaIsUnreadable()} tells the difference where it
	 * matters.
	 */
	public function schemaExists(string $id): bool
	{
		try {
			return $this->resolve($id) instanceof SchemaData;
		} catch (CorruptedStorageFileException) {
			return false;
		}
	}

	/** Whether the schema's stored file is present but cannot be decoded. */
	public function schemaIsUnreadable(string $id): bool
	{
		try {
			$this->getSchema($id);
		} catch (CorruptedStorageFileException) {
			return true;
		} catch (\Throwable) {
			return false;
		}

		return false;
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

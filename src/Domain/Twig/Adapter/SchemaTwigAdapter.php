<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Factory\LoggerFactory;

/**
 * Twig sub-adapter for schema operations.
 *
 * Accessed in Twig as `cms.schema.*`.
 */
readonly class SchemaTwigAdapter
{
	private LoggerInterface $logger;

	public function __construct(
		private SchemaLister $schemaLister,
		private SchemaFetcher $schemaFetcher,
		private DeckCompatibilityChecker $deckCompatibilityChecker,
		private CollectionEditionService $collectionEditionService,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');
	}

	/**
	 * Get all accessible schemas (filtered by edition).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function list(): array
	{
		$schemas = $this->schemaLister->listAllSchemas();

		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/**
	 * Get schema definition.
	 *
	 * @return array<string,mixed>
	 */
	public function get(string $schema): array
	{
		$schema = $this->schemaFetcher->fetchSchema($schema);

		return $schema->toArray();
	}

	/**
	 * Get all accessible reserved schemas (filtered by edition).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function reserved(): array
	{
		$schemas = $this->schemaLister->listReservedSchemas();

		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/**
	 * Get all accessible custom schemas (Pro edition only).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function custom(): array
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/**
	 * Get all accessible extension-provided schemas (Pro edition only).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function extension(): array
	{
		$schemas = $this->schemaLister->listExtensionSchemas();

		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/** @return array<string,array<array<string,mixed>>> */
	public function byCategory(): array
	{
		$customSchemas    = $this->custom();
		$extensionSchemas = $this->extension();
		$reservedSchemas  = $this->reserved();

		$categories = [];

		foreach ($extensionSchemas as $schema) {
			$category = empty($schema['category']) ? 'Extension Schemas' : trim(strval($schema['category']));
			if (!array_key_exists($category, $categories)) {
				$categories[$category] = [];
			}
			$categories[$category][] = $schema;
		}

		foreach ($customSchemas as $schema) {
			$category = empty($schema['category']) ? 'Custom Schemas' : trim(strval($schema['category']));
			if (!array_key_exists($category, $categories)) {
				$categories[$category] = [];
			}
			$categories[$category][] = $schema;
		}

		$categories['Built-in Schemas'] = $reservedSchemas;

		// Sort order: custom categories → Extension Schemas → Built-in Schemas
		uksort($categories, function ($a, $b): int {
			$order  = ['Built-in Schemas' => 3, 'Extension Schemas' => 2, 'Custom Schemas' => 1];
			$aOrder = $order[$a] ?? 0;
			$bOrder = $order[$b] ?? 0;

			if ($aOrder !== $bOrder) {
				return $aOrder <=> $bOrder;
			}

			return strcmp($a, $b);
		});

		return $categories;
	}

	/** @return array<string,mixed> */
	public function forCollection(string $collection): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		return $schema->toArray();
	}

	/**
	 * Get inherited properties for a schema.
	 *
	 * @return array<string,array{source:string,field:string,type:string,definition:array<string,mixed>}>
	 */
	public function inheritedProperties(string $schemaId): array
	{
		try {
			$schema = $this->schemaFetcher->fetchRawSchema($schemaId);

			if ($schema->inheritFrom === []) {
				return [];
			}

			$inheritedProperties = [];
			$ownPropertyNames    = array_keys($schema->properties);

			foreach ($schema->inheritFrom as $parentId) {
				try {
					$parentSchema = $this->schemaFetcher->fetchRawSchema($parentId);

					foreach ($parentSchema->properties as $propName => $propDef) {
						if (!in_array($propName, $ownPropertyNames, true) && !isset($inheritedProperties[$propName])) {
							$inheritedProperties[$propName] = [
								'source'     => $parentId,
								'field'      => $propDef['field'] ?? 'text',
								'type'       => SchemaSaver::extractPropertyType($propDef),
								'definition' => $propDef,
							];
						}
					}
				} catch (\Exception $e) {
					$this->logger->warning("Parent schema '{$parentId}' not found during inheritance resolution for '{$schemaId}'", ['error' => $e->getMessage()]);
					continue;
				}
			}

			return $inheritedProperties;
		} catch (\Exception) {
			return [];
		}
	}

	/**
	 * Check if a schema is compatible with deck usage.
	 */
	public function isDeckCompatible(string $schemaId): bool
	{
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);

			return $this->deckCompatibilityChecker->isCompatible($schema->toArray());
		} catch (\Exception $e) {
			$this->logger->warning("Schema '{$schemaId}' not found for deck compatibility check", ['error' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * Get incompatible property types for a schema when used with deck.
	 *
	 * @return array<string>
	 */
	public function deckIncompatibleTypes(string $schemaId): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);

			return $this->deckCompatibilityChecker->getSchemaIncompatibleTypes($schema->toArray());
		} catch (\Exception $e) {
			$this->logger->warning("Schema '{$schemaId}' not found for deck incompatible types check", ['error' => $e->getMessage()]);

			return [];
		}
	}
}

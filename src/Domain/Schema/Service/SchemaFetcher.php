<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

readonly class SchemaFetcher
{
	public function __construct(
		private SchemaRepository $storage,
		private CollectionRepository $collectionRepository,
	) {
	}

	public function fetchSchema(string $id): SchemaData
	{
		return $this->storage->getSchema($id);
	}

	public function schemaExists(string $id): bool
	{
		return $this->storage->schemaExists($id);
	}

	public function fetchSchemaForCollection(string $collection): SchemaData
	{
		$collectionData = $this->collectionRepository->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			throw new \UnexpectedValueException('Collection for Schema not found: ' . $collection);
		}

		return $this->storage->getSchema($collectionData->schema);
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
}

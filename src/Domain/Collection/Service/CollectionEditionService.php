<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Service for checking collection accessibility based on license edition.
 *
 * Collections using custom schemas require Pro edition. This service provides
 * methods to check accessibility and list inaccessible collections for UI display.
 */
readonly class CollectionEditionService
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private SchemaFetcher $schemaFetcher,
		private CollectionFetcher $collectionFetcher,
		private CollectionLister $collectionLister,
	) {
	}

	/**
	 * Check if a collection is accessible with current edition.
	 */
	public function isAccessible(string $collectionId): bool
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if ($collection === null) {
			return false;
		}

		return $this->isSchemaAccessible($collection->schema);
	}

	/**
	 * Check if a schema is accessible with current edition.
	 * Reserved (built-in) schemas are always accessible.
	 * Custom schemas require Pro edition.
	 */
	public function isSchemaAccessible(string $schemaId): bool
	{
		if (!$this->schemaFetcher->isCustomSchema($schemaId)) {
			return true; // Reserved schemas always accessible
		}

		return $this->editionFeatures->can(EditionFeature::CUSTOM_SCHEMAS);
	}

	/**
	 * Get list of inaccessible collections (for dashboard alert).
	 *
	 * @return array<CollectionData>
	 */
	public function getInaccessibleCollections(): array
	{
		if ($this->editionFeatures->can(EditionFeature::CUSTOM_SCHEMAS)) {
			return []; // Pro has access to all
		}

		$inaccessible = [];
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if ($this->schemaFetcher->isCustomSchema($collection->schema)) {
				$inaccessible[] = $collection;
			}
		}

		return $inaccessible;
	}

	/**
	 * Check if there are any inaccessible collections.
	 */
	public function hasInaccessibleCollections(): bool
	{
		return count($this->getInaccessibleCollections()) > 0;
	}
}

<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Service for checking collection accessibility based on license edition.
 *
 * - Blog, blog-legacy, depot schemas require Standard edition
 * - Custom schemas require Pro edition
 * - All other reserved schemas are available to all editions
 */
readonly class CollectionEditionService
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
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
	 *
	 * - Blog/blog-legacy schemas require BLOG_SCHEMA feature (Standard+)
	 * - Depot schema requires DEPOT_SCHEMA feature (Standard+)
	 * - Custom schemas require CUSTOM_SCHEMAS feature (Pro)
	 * - All other reserved schemas are always accessible
	 */
	public function isSchemaAccessible(string $schemaId): bool
	{
		// Check blog schemas (Standard+)
		if ($schemaId === 'blog' || $schemaId === 'blog-legacy') {
			return $this->editionFeatures->can(EditionFeature::BLOG_SCHEMA);
		}

		// Check depot schema (Standard+)
		if ($schemaId === 'depot') {
			return $this->editionFeatures->can(EditionFeature::DEPOT_SCHEMA);
		}

		// Check custom schemas (Pro)
		if ($this->schemaFetcher->isCustomSchema($schemaId)) {
			return $this->editionFeatures->can(EditionFeature::CUSTOM_SCHEMAS);
		}

		// All other reserved schemas are always accessible
		return true;
	}

	/**
	 * Get list of inaccessible collections (for dashboard alert).
	 *
	 * @return array<CollectionData>
	 */
	public function getInaccessibleCollections(): array
	{
		$inaccessible = [];
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if (!$this->isSchemaAccessible($collection->schema)) {
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

	/**
	 * Get list of inaccessible schemas (for admin alerts).
	 *
	 * @return array<string> Schema IDs that are inaccessible
	 */
	public function getInaccessibleSchemas(): array
	{
		$inaccessible = [];

		// Check blog schemas
		if (!$this->isSchemaAccessible('blog')) {
			$inaccessible[] = 'blog';
		}
		if (!$this->isSchemaAccessible('blog-legacy')) {
			$inaccessible[] = 'blog-legacy';
		}

		// Check depot schema
		if (!$this->isSchemaAccessible('depot')) {
			$inaccessible[] = 'depot';
		}

		// Check all custom schemas
		foreach ($this->schemaLister->listCustomSchemas() as $schema) {
			if (!$this->isSchemaAccessible($schema->id)) {
				$inaccessible[] = $schema->id;
			}
		}

		return $inaccessible;
	}

	/**
	 * Check if there are any inaccessible schemas.
	 */
	public function hasInaccessibleSchemas(): bool
	{
		return count($this->getInaccessibleSchemas()) > 0;
	}
}

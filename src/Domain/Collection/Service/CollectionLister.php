<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;

readonly class CollectionLister
{
	public function __construct(private CollectionRepository $storage)
	{
	}

	/** @return array<CollectionData> */
	public function listAllCollections(): array
	{
		return $this->storage->listAllCollections();
	}

	/** @return array<CollectionData> */
	public function listCustomCollections(): array
	{
		$collections = $this->storage->listAllCollections();

		return array_filter($collections, fn (CollectionData $collection): bool => $this->storage->isReservedCollection($collection->id) === false);
	}

	/** @return array<CollectionData> */
	public function listCollectionsWithSchema(string $schemaId): array
	{
		$collections = $this->storage->listAllCollections();

		return array_filter($collections, fn (CollectionData $collection): bool => $collection->schema === $schemaId);
	}

	/**
	 * Get a sorted list of unique category values from all collections.
	 *
	 * @return array<string>
	 */
	public function listCategories(): array
	{
		$collections = $this->listAllCollections();
		$categories  = array_map(fn (CollectionData $c): string => $c->category, $collections);

		// Filter out empty values and get unique sorted list
		$categories = array_filter($categories, fn (string $c): bool => $c !== '');
		$categories = array_unique($categories);
		sort($categories);

		return $categories;
	}
}

<?php

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\Index\Service\IndexReader;

readonly class DataViewLister
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionRepository $collectionRepository,
		private IndexReader $indexReader,
	) {
	}

	/**
	 * List all data views.
	 *
	 * @return array<mixed>
	 */
	public function listViews(): array
	{
		$this->ensureCollection();
		$index = $this->indexReader->fetchIndex(DataViewData::COLLECTION_ID);

		return $index->objects->toArray();
	}

	/** Ensure the dataviews collection exists, creating it if needed */
	public function ensureCollection(): void
	{
		if (!$this->collectionFetcher->collectionExists(DataViewData::COLLECTION_ID)) {
			$this->collectionRepository->saveReservedCollection(DataViewData::COLLECTION_ID);
		}
	}
}

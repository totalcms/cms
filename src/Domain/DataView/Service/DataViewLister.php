<?php

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\Index\Service\IndexReader;

readonly class DataViewLister
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
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

	/** Ensure the dataviews collection exists, creating it if needed. Clears cache after creation. */
	public function ensureCollection(): void
	{
		$this->collectionFetcher->fetchOrCreateReserved(DataViewData::COLLECTION_ID);
	}
}

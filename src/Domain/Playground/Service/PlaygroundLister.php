<?php

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

readonly class PlaygroundLister
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
	) {
	}

	/**
	 * List all playground snippets.
	 *
	 * @return array<mixed>
	 */
	public function listSnippets(): array
	{
		$this->ensureCollection();
		$index = $this->indexReader->fetchIndex(PlaygroundData::COLLECTION_ID);

		return $index->objects->toArray();
	}

	/** Ensure the playground collection exists, creating it if missing. */
	public function ensureCollection(): void
	{
		// fetchOrCreateReserved() actually provisions a missing reserved
		// collection; plain fetchCollection() returns null and creates nothing.
		$this->collectionFetcher->fetchOrCreateReserved(PlaygroundData::COLLECTION_ID);
	}
}

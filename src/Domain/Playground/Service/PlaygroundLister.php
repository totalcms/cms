<?php

namespace TotalCMS\Domain\Playground\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Playground\Data\PlaygroundData;

final class PlaygroundLister
{

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
	) {
	}

	/**
	 * List all playground snippets
	 * @return array<mixed>
	 */
	public function listSnippets(): array
	{
		$this->ensureCollection();
		$index = $this->indexReader->fetchIndex(PlaygroundData::COLLECTION_ID);
		return $index->objects->toArray();
	}

	/** Ensure the playground collection exists */
	public function ensureCollection(): void
	{
		if (!$this->collectionFetcher->collectionExists(PlaygroundData::COLLECTION_ID)) {
			$this->collectionFetcher->fetchCollection(PlaygroundData::COLLECTION_ID);
		}
	}
}
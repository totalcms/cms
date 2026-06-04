<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Service\EventDispatcher;

readonly class CollectionMetadataListener
{
	public function __construct(
		private CollectionSaver $collectionSaver,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('object.created', $this->onObjectCreated(...), -100);
		$dispatcher->listen('object.updated', $this->onObjectUpdated(...), -100);
		$dispatcher->listen('object.deleted', $this->onObjectDeleted(...), -100);
		// Before the index rebuild (-100): we must drop the in-memory OID bump
		// and write the true count before anything else reads the cache.
		$dispatcher->listen('import.completed', $this->onImportCompleted(...), -110);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectCreated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->incrementCount($collection);
		$this->collectionSaver->incrementTotalObjects($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectUpdated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->updateLastUpdated($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectDeleted(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->collectionSaver->decrementTotalObjects($collection);
	}

	/**
	 * Flush the collection count once at the end of a batch import.
	 *
	 * `object.created` is suppressed during import, so the per-object count
	 * increment never runs — the running OID counter is kept in memory by
	 * ObjectImporter (via CollectionFetcher::incrementCachedCount). Here we
	 * persist the batch's created count to disk in a single write. `count` is
	 * the lifetime "objects created" counter (the oid-autogen source);
	 * `totalObjects` self-heals from the freshly-rebuilt index inside
	 * updateCollection, so we only drive `count`.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function onImportCompleted(array $payload): void
	{
		$collection = (string)$payload['collection'];
		$created    = is_array($payload['created'] ?? null) ? $payload['created'] : [];

		$createdCount = count($created);
		if ($createdCount === 0) {
			return;
		}

		// Drop the in-memory OID bump (ObjectImporter advanced the cached count
		// per object so OIDs stayed unique). Without this, incrementCount would
		// read the bumped cache value and double-count. The cache is no longer
		// needed for OIDs — the batch is done — so re-read the true on-disk base
		// and add the created count once.
		$this->collectionFetcher->clearCache($collection);
		$this->collectionSaver->incrementCount($collection, $createdCount);
	}
}

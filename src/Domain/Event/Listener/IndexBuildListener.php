<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;

class IndexBuildListener
{
	public function __construct(
		private readonly IndexBuilder $indexBuilder,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionLister $collectionLister,
		private readonly EventDispatcher $eventDispatcher,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('object.created', $this->onObjectCreated(...), -100);
		$dispatcher->listen('object.updated', $this->onObjectUpdated(...), -100);
		$dispatcher->listen('object.deleted', $this->onObjectDeleted(...), -100);
		$dispatcher->listen('schema.saved', $this->onSchemaSaved(...), -100);
		$dispatcher->listen('import.completed', $this->onImportCompleted(...), -100);
		$dispatcher->listen('bulk.deleted', $this->onBulkDeleted(...), -100);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectCreated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		if ($this->eventDispatcher->isIndexRebuildSuspended($collection)) {
			return;
		}

		$object = $payload['object'] ?? null;

		$this->indexBuilder->smartBuildIndex(
			$collection,
			$object instanceof ObjectData ? $object : null,
		);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectUpdated(array $payload): void
	{
		$collection = (string)$payload['collection'];

		if ($this->eventDispatcher->isIndexRebuildSuspended($collection)) {
			return;
		}

		$object = $payload['object'] ?? null;

		$this->indexBuilder->smartBuildIndex(
			$collection,
			$object instanceof ObjectData ? $object : null,
		);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectDeleted(array $payload): void
	{
		$collection = (string)$payload['collection'];
		$id         = (string)$payload['id'];

		// During a bulk delete the per-object rebuild is suspended; the single
		// rebuild happens once in onBulkDeleted at the end of the batch.
		if ($this->eventDispatcher->isIndexRebuildSuspended($collection)) {
			return;
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		$queueReindex   = $collectionData instanceof CollectionData && $collectionData->queueRebuildOnSave;

		if ($queueReindex) {
			$this->indexBuilder->removeObjectFromIndex($collection, $id);
		}

		$this->indexBuilder->smartBuildIndex($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onSchemaSaved(array $payload): void
	{
		$schemaId    = (string)$payload['schema'];
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if ($collection->schema === $schemaId) {
				$this->indexBuilder->smartBuildIndex($collection->id);
			}
		}
	}

	/** @param array<string,mixed> $payload */
	public function onImportCompleted(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->eventDispatcher->resumeIndexRebuild($collection);
		$this->indexBuilder->buildIndex($collection);
	}

	/**
	 * End of a bulk delete: resume per-object rebuilds and do the single index
	 * rebuild the batch deferred. `totalObjects` self-heals from the rebuilt
	 * index, so the suppressed per-object metadata decrements need no flush.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function onBulkDeleted(array $payload): void
	{
		$collection = (string)$payload['collection'];

		$this->eventDispatcher->resumeIndexRebuild($collection);
		$this->indexBuilder->buildIndex($collection);
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;

class IndexBuildListener
{
	/** @var array<string,bool> Collections with suspended per-object index rebuilds */
	private array $suspendedCollections = [];

	public function __construct(
		private readonly IndexBuilder $indexBuilder,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionLister $collectionLister,
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->listen('object.created', $this->onObjectCreated(...), -100);
		$dispatcher->listen('object.updated', $this->onObjectUpdated(...), -100);
		$dispatcher->listen('object.deleted', $this->onObjectDeleted(...), -100);
		$dispatcher->listen('schema.saved', $this->onSchemaSaved(...), -100);
		$dispatcher->listen('import.completed', $this->onImportCompleted(...), -100);
	}

	/**
	 * Suspend per-object index rebuilds for a collection.
	 * Used during batch imports to avoid rebuilding after every object.
	 */
	public function suspendForCollection(string $collection): void
	{
		$this->suspendedCollections[$collection] = true;
	}

	/**
	 * Resume per-object index rebuilds for a collection.
	 */
	public function resumeForCollection(string $collection): void
	{
		unset($this->suspendedCollections[$collection]);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectCreated(array $payload): void
	{
		$collection = (string) $payload['collection'];

		if (isset($this->suspendedCollections[$collection])) {
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
		$collection = (string) $payload['collection'];

		if (isset($this->suspendedCollections[$collection])) {
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
		$collection = (string) $payload['collection'];
		$id = (string) $payload['id'];

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		$queueReindex = $collectionData instanceof CollectionData && $collectionData->queueRebuildOnSave;

		if ($queueReindex) {
			$this->indexBuilder->removeObjectFromIndex($collection, $id);
		}

		$this->indexBuilder->smartBuildIndex($collection);
	}

	/** @param array<string,mixed> $payload */
	public function onSchemaSaved(array $payload): void
	{
		$schemaId = (string) $payload['schema'];
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
		$collection = (string) $payload['collection'];

		$this->resumeForCollection($collection);
		$this->indexBuilder->buildIndex($collection);
	}
}

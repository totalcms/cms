<?php

namespace TotalCMS\Domain\Index\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

readonly class IndexBuilder
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private CollectionFetcher $collectionFetcher,
		private JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('indexbuilder');
	}

	public function buildIndex(string $collection): IndexData
	{
		$objectIds  = $this->storage->fetchObjectIds($collection);
		$index      = new IndexData();

		if (count($objectIds) > 0) {
			$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$indexProps = $schema->index;

			foreach ($objectIds as $id) {
				try {
					$object  = $this->objectFetcher->fetchObject($collection, $id);
					// The reject method is used to filter out properties that are not in the index
					// The map method is used to transform the properties into an array
					$summary = $object->properties
						->reject(fn ($value, $key): bool => !in_array($key, $indexProps, true))
						->map(fn ($property): mixed => $property->transform());
					$summary->put('id', $id);
					$index->objects->push($summary->toArray());
				} catch (\Throwable $e) {
					// Skip objects that fail to load (e.g., type mismatches after schema changes)
					// Log the error but continue building index with remaining valid objects
					$this->logger->warning('Skipping object during index build due to error', [
						'collection' => $collection,
						'object_id'  => $id,
						'error'      => $e->getMessage(),
						'exception'  => $e::class,
					]);
				}
			}
		}

		$this->storage->saveIndex($collection, $index);

		return $index;
	}

	/**
	 * Append a new object to the existing index for immediate visibility.
	 * This is more efficient than rebuilding the entire index.
	 */
	public function appendObjectToIndex(string $collection, ObjectData $object): void
	{
		// Get existing index or create new one if it doesn't exist
		$index = $this->storage->fetchIndex($collection) ?? new IndexData();

		// Remove existing entry with same ID (for updates) and append new one
		$index->objects = $index->objects->reject(fn ($item): bool => $item['id'] === $object->id);
		$index->objects->push($object->toArray());

		// Save the updated index
		$this->storage->saveIndex($collection, $index);
	}

	/**
	 * Remove an object from the index.
	 */
	public function removeObjectFromIndex(string $collection, string $objectId): void
	{
		$index = $this->storage->fetchIndex($collection);
		if (!$index instanceof IndexData) {
			return; // No index exists, nothing to remove
		}

		// Remove the object from the index
		$index->objects = $index->objects->reject(fn ($item): bool => $item['id'] === $objectId);

		// Save the updated index
		$this->storage->saveIndex($collection, $index);
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function smartBuildIndex(string $collection, ?ObjectData $newObject = null): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData) {
			throw new \DomainException(sprintf('Collection %s not found', $collection));
		}
		$queueReindex = $collectionData->queueRebuildOnSave ?? false;

		// If we have a new object and queueRebuildOnSave is enabled,
		// append the object immediately for visibility, then queue full rebuild
		if ($queueReindex && $newObject instanceof ObjectData) {
			$this->appendObjectToIndex($collection, $newObject);
			$this->jobQueuer->queueBuildIndex($collection);
		} elseif ($queueReindex) {
			// No new object provided, just queue the rebuild
			$this->jobQueuer->queueBuildIndex($collection);
		} else {
			// Immediate rebuild
			$this->buildIndex($collection);
		}
	}
}

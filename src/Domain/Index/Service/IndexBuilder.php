<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;

final class IndexBuilder
{
	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private CollectionSchemaFetcher $schemaFetcher,
		private CollectionFetcher $collectionFetcher,
		private JobQueuer $jobQueuer,
	) {
	}

	public function buildIndex(string $collection): IndexData
	{
		$objectIds  = $this->storage->fetchObjectIds($collection);
		$index      = new IndexData();

		if (count($objectIds) > 0) {
			$schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$indexProps = $schema->index;

			foreach ($objectIds as $id) {
				$object  = $this->objectFetcher->fetchObject($collection, $id);
				// The reject method is used to filter out properties that are not in the index
				// The map method is used to transform the properties into an array
				$summary = $object->properties
					->reject(fn ($value, $key) => !in_array($key, $indexProps, true))
					->map(fn ($property) => $property->transform());
				$summary->put('id', $id);
				$index->objects->push($summary->toArray());
			}
		}

		$this->storage->saveIndex($collection, $index);

		return $index;
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function smartBuildIndex(string $collection): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			throw new \DomainException(sprintf('Collection %s not found', $collection));
		}
		$queueReindex = $collectionData->queueRebuildOnSave ?? false;

		// Queue the reindex if the collection is set to do so
		if ($queueReindex) {
			$this->jobQueuer->queueBuildIndex($collection);
		} else {
			$this->buildIndex($collection);
		}
	}
}

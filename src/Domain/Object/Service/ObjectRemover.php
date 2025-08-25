<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class ObjectRemover
{
	public function __construct(
		private PropertyRepository $propStorage,
		private ObjectRepository $storage,
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private IndexBuilder $indexBuilder,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	public function deleteObject(string $collection, string $id): bool
	{
		$status = $this->storage->deleteObject($collection, $id);

		if ($status) {
			// Use optimized removal for immediate index update
			$collectionData = $this->collectionFetcher->fetchCollection($collection);
			$queueReindex   = $collectionData instanceof CollectionData && ($collectionData->queueRebuildOnSave ?? false);

			if ($queueReindex) {
				// Remove immediately from index, then queue full rebuild for consistency
				$this->indexBuilder->removeObjectFromIndex($collection, $id);
			}
			// Full rebuild
			$this->indexBuilder->smartBuildIndex($collection);
		}

		return $status;
	}

	public function deleteObjectProperty(string $collection, string $id, string $property): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$objectData            = $object->toArray();
		$objectData[$property] = null;

		$this->propStorage->deleteDirectory($collection, $id, $property);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}
}

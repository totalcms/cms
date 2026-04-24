<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\EventDispatcher;
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
		private EventDispatcher $eventDispatcher,
	) {
	}

	public function deleteObject(string $collection, string $id): bool
	{
		$status = $this->storage->deleteObject($collection, $id);

		if ($status) {
			$this->eventDispatcher->dispatch('object.deleted', [
				'collection' => $collection,
				'id'         => $id,
			]);
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

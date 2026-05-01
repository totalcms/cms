<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
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
			$this->eventDispatcher->dispatch('object.deleted', new ObjectEventPayload($collection, $id));
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

	/**
	 * Delete a property nested inside another property (e.g. an image child of
	 * a card). Clears `obj[$parent][$child]` from the JSON and removes the
	 * matching nested directory on disk. Sibling card children are preserved.
	 */
	public function deleteNestedProperty(string $collection, string $id, string $parent, string $child): ObjectData
	{
		$object     = $this->objectFetcher->fetchObject($collection, $id);
		$objectData = $object->toArray();

		if (isset($objectData[$parent]) && is_array($objectData[$parent])) {
			$objectData[$parent][$child] = null;
		}

		$this->propStorage->deleteDirectory($collection, $id, $parent, null, $child);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}
}

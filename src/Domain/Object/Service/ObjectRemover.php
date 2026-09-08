<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
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
			$this->eventDispatcher->dispatch(CoreEvent::OBJECT_DELETED, new ObjectEventPayload($collection, $id));
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
	 * Delete a property nested inside another property:
	 *   - Card child: `obj[$parent][$path]` where `$path` is a single segment.
	 *   - Deck child: `obj[$parent][$itemId][$child]` where `$path` is `"itemId/child"`.
	 *
	 * Removes the leaf from the JSON (the card/deck factory regenerates it as
	 * the child's empty shape on save) and removes the matching nested
	 * directory on disk. Siblings at every level are preserved. When the walk
	 * finds no such item the JSON is left exactly as it was — only the
	 * directory is cleaned up.
	 */
	public function deleteNestedProperty(string $collection, string $id, string $parent, string $path): ObjectData
	{
		$object     = $this->objectFetcher->fetchObject($collection, $id);
		$objectData = $object->toArray();

		$segments = $path === '' ? [] : explode('/', $path);
		if ($segments !== [] && isset($objectData[$parent]) && is_array($objectData[$parent])) {
			$cursor =&$objectData[$parent];
			$leaf   = array_pop($segments);
			$found  = true;
			foreach ($segments as $segment) {
				if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
					// Nothing to delete — intermediate slot doesn't exist. Never
					// assign through $cursor here: it still references the parent
					// property, and nulling that wiped whole decks.
					$found = false;
					break;
				}
				$cursor =&$cursor[$segment];
			}
			if ($found) {
				unset($cursor[$leaf]);
			}
			unset($cursor);
		}

		$this->propStorage->deleteDirectory($collection, $id, $parent, null, $path);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}
}

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
		// Read the record before it goes so `object.deleted` can carry what was
		// deleted. Without this every listener is blind to the content — the
		// backup store, for one, has nothing to keep. Nullable fetch: deleting
		// an already-missing object is not an error here.
		$previous = $this->storage->fetchObject($collection, $id);

		$status = $this->storage->deleteObject($collection, $id);

		if ($status) {
			$this->eventDispatcher->dispatch(CoreEvent::OBJECT_DELETED, new ObjectEventPayload($collection, $id, null, $previous));
		}

		return $status;
	}

	public function deleteObjectProperty(string $collection, string $id, string $property): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$objectData            = $object->toArray();
		$objectData[$property] = null;

		// Record first, disk second. The save runs whole-object validation and
		// can be refused for a reason unrelated to this property (a maxLength
		// added after the content was written, say). Deleting the directory
		// first left the record naming a file that no longer existed — and
		// the file unrecoverable. A refused save now leaves everything as it
		// was; a failed directory delete after a successful save leaves an
		// orphan on disk, which nothing references and which is recoverable.
		$updated = $this->objectUpdater->updateObject($collection, $id, $objectData);

		$this->propStorage->deleteDirectory($collection, $id, $property);

		return $updated;
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

		// Record first, disk second — see deleteObjectProperty().
		$updated = $this->objectUpdater->updateObject($collection, $id, $objectData);

		$this->propStorage->deleteDirectory($collection, $id, $parent, null, $path);

		return $updated;
	}
}

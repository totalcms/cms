<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;

/**
 * Writes an imported object with import event semantics: the collection's
 * `object.created` / `object.updated` is suppressed so user-facing listeners
 * never see import-time writes, and `import.created` / `import.updated`
 * fires instead for import-specific listeners.
 *
 * Dates are preserved on both paths. Imported data is the authoritative
 * history of its source; restamping would make the copy read newer than the
 * original and poison any freshness comparison between the two sides.
 */
readonly class ImportedObjectWriter
{
	public function __construct(
		private ObjectSaver $objectSaver,
		private ObjectUpdater $objectUpdater,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function create(string $collection, array $objectData): ObjectData
	{
		$this->eventDispatcher->suspendForImport($collection);
		try {
			$object = $this->objectSaver->saveObject($collection, $objectData, preserveDates: true);
			$this->eventDispatcher->dispatch(CoreEvent::IMPORT_CREATED, new ObjectEventPayload($collection, $object->id, $object));

			return $object;
		} finally {
			$this->eventDispatcher->resumeForImport($collection);
		}
	}

	/**
	 * The upsert path (sync push): the existing storage row is replaced
	 * through ObjectUpdater rather than conflicted-out.
	 *
	 * @param array<string,mixed> $objectData
	 */
	public function update(string $collection, string $id, array $objectData): ObjectData
	{
		$this->eventDispatcher->suspendForImport($collection);
		try {
			$objectData['id'] = $id;
			$object           = $this->objectUpdater->updateObject($collection, $id, $objectData, preserveDates: true);
			$this->eventDispatcher->dispatch(CoreEvent::IMPORT_UPDATED, new ObjectEventPayload($collection, $object->id, $object));

			return $object;
		} finally {
			$this->eventDispatcher->resumeForImport($collection);
		}
	}
}

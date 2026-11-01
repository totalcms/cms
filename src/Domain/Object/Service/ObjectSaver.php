<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OidAllocator;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Exception\ObjectExistsException;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Storage\FileLock;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

readonly class ObjectSaver
{
	public function __construct(
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private PropertyDataProcessorInterface $propertyProcessor,
		private DateFieldResetter $dateFieldResetter,
		private EventDispatcher $eventDispatcher,
		private CollectionFetcher $collectionFetcher,
		private IndexRepository $indexRepository,
		private ObjectWriteGate $gate,
		private FileLock $fileLock,
		private OidAllocator $oids,
	) {
	}

	/**
	 * @param array<string,mixed> $objectData
	 * @param bool                $preserveDates Keep authored values in onCreate/onUpdate
	 *                                           date fields instead of restamping them.
	 *                                           Used by imports: the incoming data is the
	 *                                           authoritative history of its source object,
	 *                                           and restamping would make every imported
	 *                                           copy look newer than the original it
	 *                                           mirrors. Empty date fields still stamp
	 *                                           normally.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function saveObject(string $collection, array $objectData, bool $preserveDates = false): ObjectData
	{
		// Singleton: the one object is always stored at the collection id. Force
		// it before generating, so a submitted/overridden id can't break the
		// invariant, and reject a second object — the single slot is taken.
		// (The re-key path uses ObjectCloner, which writes via the repository
		// and bypasses this.)
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData instanceof CollectionData && $collectionData->singleton) {
			$objectData['id'] = $collection;
			if (count($this->indexRepository->fetchObjectIds($collection)) >= 1) {
				throw new \DomainException(sprintf(
					'Collection "%s" is a singleton and already holds its object.',
					$collection,
				));
			}
		}

		// The owner stamp and the protected and forced fields for whoever is
		// acting; a no-op for trusted actors and unprotected collections.
		$objectData = $this->gate->beforeCreate($collection, $objectData);

		// A create claims its ${oid} number while the id is generated, so two
		// creates that overlap cannot get the same one. An import keeps its own
		// running count instead (see OidAllocator).
		$import   = $this->eventDispatcher->isImportSuspended($collection);
		$claimed  = false;
		$generate = fn (): ObjectData => $this->factory->generateObject($collection, $objectData);
		$object   = $import ? $generate() : $this->oids->creating($collection, $generate, $claimed);

		if ($this->storage->existsObject($collection, $object->id)) {
			throw new ObjectExistsException($collection, $object->id);
		}

		$this->prepareForCreate($collection, $object, $preserveDates);
		$this->createObject($collection, $object);

		// Counted only once written, so a refused create changes nothing.
		if (!$import) {
			$this->oids->recordCreate($collection, $claimed);
		}

		$this->eventDispatcher->dispatch(CoreEvent::OBJECT_CREATED, new ObjectEventPayload($collection, $object->id, $object));

		return $object;
	}

	/**
	 * Write a new object, failing instead of writing over one already on disk.
	 * The check and the write run under the collection's create lock, so two
	 * creates of the same id cannot both find it free: the second one throws.
	 *
	 * @throws ObjectExistsException
	 */
	private function createObject(string $collection, ObjectData $object): void
	{
		$this->fileLock->withLock(PathUtils::buildPath(collection: $collection, filename: '.create'), function () use ($collection, $object): void {
			if ($this->storage->existsObject($collection, $object->id)) {
				throw new ObjectExistsException($collection, $object->id);
			}

			$this->storage->saveObject($collection, $object);
		});
	}

	/** Reset creation dates and run the save-time property actions. */
	private function prepareForCreate(string $collection, ObjectData $object, bool $preserveDates): void
	{
		// Reset onCreate date fields to current time (handles duplications where
		// the original date was copied). Skipped when preserving: an authored
		// creation date is history, and processBeforeSave still fills any empty
		// creation field with the current time.
		if (!$preserveDates) {
			$this->dateFieldResetter->resetOnCreateFields($object, $collection);
		}

		// Run property actions before saving (ex: onUpdate date). In preserve
		// mode, a date field that already carries an authored value is left
		// untouched; everything else still runs through the processor.
		$object->properties = $object->properties->map(function (PropertyData $property) use ($preserveDates): PropertyData {
			if ($preserveDates && $property instanceof DateData && $property->hasAuthoredDate()) {
				return $property;
			}

			return $this->propertyProcessor->processBeforeSave($property);
		});
	}
}

<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

readonly class ObjectSaver
{
	public function __construct(
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private PropertyDataProcessorInterface $propertyProcessor,
		private DateFieldResetter $dateFieldResetter,
		private EventDispatcher $eventDispatcher,
		private \TotalCMS\Domain\Collection\Service\CollectionFetcher $collectionFetcher,
		private \TotalCMS\Domain\Index\Repository\IndexRepository $indexRepository,
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function saveObject(string $collection, array $objectData): ObjectData
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

		$object = $this->factory->generateObject($collection, $objectData);

		if ($this->storage->existsObject($collection, $object->id)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
		}

		// Reset onCreate date fields to current time (handles duplications where the original date was copied)
		$this->dateFieldResetter->resetOnCreateFields($object, $collection);

		// Run property actions before saving (ex: onUpdate date)
		$object->properties = $object->properties->map(fn (PropertyData $property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$this->storage->saveObject($collection, $object);

		$this->eventDispatcher->dispatch(CoreEvent::OBJECT_CREATED, new ObjectEventPayload($collection, $object->id, $object));

		return $object;
	}
}

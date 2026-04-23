<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\EventDispatcher;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

readonly class ObjectSaver
{
	public function __construct(
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private IndexBuilder $indexBuilder,
		private PropertyDataProcessorInterface $propertyProcessor,
		private CollectionSaver $collectionSaver,
		private DataViewUpdateScheduler $viewUpdateScheduler,
		private DateFieldResetter $dateFieldResetter,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function saveObject(string $collection, array $objectData): ObjectData
	{
		$object = $this->factory->generateObject($collection, $objectData);

		if ($this->storage->existsObject($collection, $object->id)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
		}

		// Reset onCreate date fields to current time (handles duplications where the original date was copied)
		$this->dateFieldResetter->resetOnCreateFields($object, $collection);

		// Run property actions before saving (ex: onUpdate date)
		$object->properties = $object->properties->map(fn (PropertyData $property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$this->storage->saveObject($collection, $object);

		// Increment the collection count for newly created objects
		$this->collectionSaver->incrementCount($collection);

		// Increment totalObjects and update lastUpdated
		$this->collectionSaver->incrementTotalObjects($collection);

		// Pass the new object for immediate index append when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($collection, $object);

		$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);

		$this->eventDispatcher->dispatch('object.created', [
			'collection' => $collection,
			'id'         => $object->id,
		]);

		return $object;
	}
}

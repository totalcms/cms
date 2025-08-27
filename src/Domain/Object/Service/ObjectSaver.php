<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
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
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function saveObject(string $collection, array $objectData): ObjectData
	{
		$object = $this->factory->generateObject($collection, $objectData);

		if ($this->storage->existsObject($collection, $object->id)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
		}

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn ($property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$this->storage->saveObject($collection, $object);

		// Increment the collection count for newly created objects
		$this->collectionSaver->incrementCount($collection);

		// Pass the new object for immediate index append when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($collection, $object);

		return $object;
	}
}

<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

readonly class ObjectUpdater
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectRepository $storage,
		private ObjectFactory $factory,
		private IndexBuilder $indexBuilder,
		private PropertyDataProcessorInterface $propertyProcessor,
		private CollectionSaver $collectionSaver,
	) {
	}

	/** @param ObjectData|array<string,mixed> $object */
	public function updateObject(string $collection, string $id, ObjectData|array $object): ObjectData
	{
		if (!$object instanceof ObjectData) {
			$object = $this->factory->generateObject($collection, $object);
		}

		if ($object->id !== $id) {
			throw new \UnexpectedValueException('Invalid Object data provided. Does not match object ID.', 1);
		}

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn ($property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$this->storage->saveObject($collection, $object);

		// Update lastUpdated timestamp
		$this->collectionSaver->updateLastUpdated($collection);

		// Pass the updated object for immediate index update when queueRebuildOnSave is enabled
		$this->indexBuilder->smartBuildIndex($collection, $object);

		return $object;
	}

	/** @param array<string,mixed> $newData */
	public function updateObjectProperty(string $collection, string $id, string $property, array $newData): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		// Run property actions before saving (ex: update date)
		$object->properties = $object->properties->map(fn ($property): PropertyData => $this->propertyProcessor->processBeforeSave($property));

		$objectData            = $object->toArray();
		$objectData[$property] = $newData;

		return $this->updateObject($collection, $id, $objectData);
	}

	/** @param array<string,mixed> $newData */
	public function updateObjectPropertyMeta(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $newData,
		?string $subpath = null,
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$property = $object->properties->get($property);

		if (!$property instanceof PropertyData) {
			throw new \UnexpectedValueException('Unable to locate object property to update');
		}

		if ($property instanceof DepotData) {
			// Update a depot because they are different - **this mutates $property**
			$depotUpdater = new DepotPropertyManager($property);
			// There is no "update" meta API, so we just merge with existing data
			$depotUpdater->patchMeta($name, $newData, $subpath);
		}

		$object->properties = $object->properties->map(function ($item) use ($property): PropertyData {
			if ($item->id === $property->id) {
				$item = $property;
			}

			return $item;
		});

		return $this->updateObject($collection, $id, $object);
	}
}

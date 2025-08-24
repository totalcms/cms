<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;

final readonly class ObjectPatcher
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
	) {
	}

	/** @param array<string,mixed> $newData */
	public function patchObject(string $collection, string $id, array $newData): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$mergedObject = array_merge($object->toArray(), $newData);

		return $this->objectUpdater->updateObject($collection, $id, $mergedObject);
	}

	/** @param array<string,mixed> $newData */
	public function patchObjectProperty(string $collection, string $id, string $property, array $newData): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$objectData            = $object->toArray();
		$objectData[$property] = array_merge($objectData[$property], $newData);

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param array<string,mixed> $newData
	 */
	public function patchObjectPropertyMeta(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $newData,
		?string $subpath = null,
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $id);

		$propertyData = $object->properties->get($property);

		if (!$propertyData instanceof PropertyData) {
			throw new \UnexpectedValueException('Unable to locate object property to patch');
		}

		$patch = $propertyData->transform();

		if ($propertyData instanceof DepotData) {
			// Update a depot because they are different - **this mutates $property**
			$depotUpdater = new DepotPropertyManager($propertyData);
			$depotUpdater->patchMeta($name, $newData, $subpath);

			$patch = $propertyData->transform();
		} else {
			// Update a normal property
			foreach ($patch as $index => $child) {
				if ($child['name'] === $name) {
					$patch[$index] = array_merge($child, $newData);
					break;
				}
			}
		}

		$objectData            = $object->toArray();
		$objectData[$property] = $patch;

		return $this->objectUpdater->updateObject($collection, $id, $objectData);
	}
}

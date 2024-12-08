<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

class FileRemover
{
	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	protected function fetchProperty(string $collection, string $objectID, string $property): PropertyData
	{
		// Get the existing object property data
		$fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

		return $fileProperty;
	}

	/** @param array<array<string,mixed>> $data */
	protected function updateObject(string $collection, string $objectID, string $property, array $data): ObjectData
	{
		$propertyData = [$property => $data];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}

	public function deleteFile(
		string $collection,
		string $objectID,
		string $property,
		string $name,
		?string $subpath = null
	): ObjectData
	{
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$this->storage->deleteFile($collection, $objectID, $property, $name);

		$files = $this->fetchProperty($collection, $objectID, $property)->transform();
		foreach ($files as $key => $file) {
			if ($file['name'] === $name) {
				unset($files[$key]);
				break;
			}
		}

		// Reindex the array
		$files = array_values($files);

		return $this->updateObject($collection, $objectID, $property, $files);
	}
}

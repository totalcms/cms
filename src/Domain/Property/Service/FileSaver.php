<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

class FileSaver
{
	public string $type = 'file';

	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectSaver $objectSaver,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null
	): ObjectData
	{
		// $subpath argument is only supported in DepotSaver at this time

		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		// Clean up existing files in the path. Only one file should exist
		$this->storage->deleteDirectory($collection, $objectID, $property);

		// Update the object with the new file data
		$fileInfo = $this->storage->saveFile($collection, $objectID, $property, $filePath);

		// File object stores original filename as 'filename'
		$fileInfo['filename'] = $fileInfo['name'];

		$newData = $fileInfo;

		if ($objectExists) {
			// If the object existed before, we will keep the existing data
			$fileProperty = $this->fetchProperty($collection, $objectID, $property);
			$keep         = ['name', 'comments', 'tags', 'protected', 'password'];
			$existingData = array_filter($fileProperty->transform(), fn ($key) => in_array($key, $keep), ARRAY_FILTER_USE_KEY);
			if (!empty($existingData['name'])) {
				// make sure that it's not an empty file property, filename would not be empty

				// Update the extension of the name if the new file has a different extension
				$newExt      = pathinfo((string)$fileInfo['name'], PATHINFO_EXTENSION);
				$existingExt = pathinfo((string)$existingData['name'], PATHINFO_EXTENSION);

				if ($newExt !== $existingExt) {
					$existingData['name'] = pathinfo($existingData['name'], PATHINFO_FILENAME) . '.' . $newExt;
				}

				$fileInfo = array_merge($fileInfo, $existingData);
			}
			$newData = array_merge($fileProperty->transform(), $fileInfo);
		}

		$fileData = new FileData($newData);

		return $this->updateObject($collection, $objectID, $property, $fileData);
	}

	protected function createObject(string $collection, string $objectID, string $property): void
	{
		try {
			$this->objectSaver->saveObject($collection, [
				'id'      => $objectID,
				$property => $this->createPropertyObject($collection, $property)->transform(),
			]);
		} catch (\Exception $e) {
			$msg = "Object $objectID does not exist in collection $collection to save file ($property) to.";
			throw new \UnexpectedValueException($msg . $e->getMessage());
		}
	}

	protected function createPropertyObject(string $collection, string $property): PropertyData
	{
		$type  = ucfirst($this->type);
		$class = "TotalCMS\\Domain\\Property\\Data\\{$type}Data";

		if (!class_exists($class)) {
			throw new \UnexpectedValueException("Invalid file type $type found for property $property in collection $collection");
		}

		$fileProperty = new $class();

		if (!$fileProperty instanceof PropertyData) {
			throw new \DomainException('Error creating property for object.');
		}

		return $fileProperty;
	}

	protected function fetchProperty(string $collection, string $objectID, string $property): PropertyData
	{
		// Get the existing object property data
		$fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

		if (!$fileProperty instanceof PropertyData) {
			return $this->createPropertyObject($collection, $property);
		}

		return $fileProperty;
	}

	protected function updateObject(string $collection, string $objectID, string $property, PropertyData $data): ObjectData
	{
		$propertyData = [$property => $data->transform()];

		return $this->objectSaver->patchObject($collection, $objectID, $propertyData);
	}
}

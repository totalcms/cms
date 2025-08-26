<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Traits\LoggerAwareTrait;

class FileSaver
{
	use LoggerAwareTrait;

	public string $type = 'file';

	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectSaver $objectSaver,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
		protected LoggerFactory $loggerFactory,
	) {
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null,
	): ObjectData {
		// $subpath argument is only supported in DepotSaver at this time

		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		// Clean up existing files in the path. Only one file should exist
		$this->storage->deleteDirectory($collection, $objectID, $property);

		// Update the object with the new file data
		$fileInfo = $this->storage->saveFile($collection, $objectID, $property, $filePath);

		$newData = $fileInfo;

		if ($objectExists) {
			// If the object existed before, we will keep the existing data
			$fileProperty = $this->fetchProperty($collection, $objectID, $property);
			$keep         = ['download', 'comments', 'tags', 'protected', 'password'];
			$existingData = array_filter($fileProperty->transform(), fn ($key): bool => in_array($key, $keep), ARRAY_FILTER_USE_KEY);
			if (!empty($existingData['download'])) {
				// Update the extension of the name if the new file has a different extension
				$newExt      = pathinfo((string)$fileInfo['name'], PATHINFO_EXTENSION);
				$existingExt = pathinfo((string)$existingData['download'], PATHINFO_EXTENSION);

				if ($newExt !== $existingExt) {
					$existingData['download'] = pathinfo((string) $existingData['download'], PATHINFO_FILENAME) . '.' . $newExt;
				}
			}
			$newData = array_merge($fileProperty->transform(), $fileInfo, $existingData);
		}

		// if ($objectExists) {
		// 	// If the object existed before, we will keep the existing data
		// 	$newImage = array_merge($newData, $existingData);
		// }

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
			throw new \UnexpectedValueException($msg . $e->getMessage(), $e->getCode(), $e);
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
		try {
			// Get the existing object property data
			$fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		} catch (\UnexpectedValueException) {
			$fileProperty = $this->createPropertyObject($collection, $property);
		}

		return $fileProperty;
	}

	protected function updateObject(string $collection, string $objectID, string $property, PropertyData $data): ObjectData
	{
		$propertyData = [$property => $data->transform()];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}
}

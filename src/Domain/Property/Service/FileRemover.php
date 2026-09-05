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
		?string $subpath = null,
	): ObjectData {
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$this->storage->deleteFile($collection, $objectID, $property, $name);

		$files = $this->fetchProperty($collection, $objectID, $property)->transform();

		// This is the fallback remover for every property type without one of
		// its own, which is not just the gallery shape it was written for.
		// GalleryData::transform() returns a LIST of file maps; ImageData and
		// FileData return a flat map of a single file's own fields, so the
		// filter below was handed `'upload-test.png'['name']` and threw
		// "Cannot access offset of type string on string" — a 500 on every
		// delete from a single-image or single-file property.
		//
		// There is no list to filter in the single-value case: the file that
		// was just deleted IS the property, so removing it empties it.
		if (!array_is_list($files)) {
			return $this->updateObject($collection, $objectID, $property, []);
		}

		foreach ($files as $key => $file) {
			if (is_array($file) && ($file['name'] ?? null) === $name) {
				unset($files[$key]);
				break;
			}
		}

		// Reindex the array
		$files = array_values($files);

		return $this->updateObject($collection, $objectID, $property, $files);
	}
}

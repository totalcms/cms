<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Utils\ImageMetaReader;
use TotalCMS\Utils\ImagePaletteGenerator;

final class ImageSaver extends FileSaver
{
	public string $type = 'image';

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null,
	): ObjectData {
		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		// Clean up existing files in the path. Only one file should exist
		// This also cleans the cache
		$this->storage->deleteDirectory($collection, $objectID, $property);

		// Update the object with the new file data
		$imageProp = $this->fetchProperty($collection, $objectID, $property);

		// Only keep the data for alt, featrued, link, and tags
		$keep         = ['alt', 'featured', 'link', 'tags'];
		$existingData = array_filter($imageProp->transform(), fn ($key) => in_array($key, $keep), ARRAY_FILTER_USE_KEY);

		$fileData  = $this->storage->saveFile($collection, $objectID, $property, $filePath);
		$colorData = ['palette' => ImagePaletteGenerator::getPalette($filePath)];
		$metaData  = ImageMetaReader::getMetaData($filePath);

		$newImage = array_merge($existingData, $fileData, $metaData, $colorData);

		if ($objectExists) {
			// If the object existed before, we will keep the existing data
			$newImage = array_merge($newImage, $existingData);
		}

		return $this->updateObject($collection, $objectID, $property, new ImageData($newImage));
	}
}

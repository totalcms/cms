<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\ImageData;

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

		// Safely generate color palette - never let this break the upload
		try {
			$colorData = ['palette' => ImagePaletteGenerator::getPalette($filePath)];
		} catch (\RuntimeException $e) {
			// Log palette generation failures
			$this->getLogger()->warning('Palette generation failed', [
				'collection' => $collection,
				'objectID'   => $objectID,
				'property'   => $property,
				'file'       => $filePath,
				'error'      => $e->getMessage(),
			]);
			// Continue with empty palette - upload should not fail
			$colorData = ['palette' => []];
		}

		$metaData  = ImageMetaReader::getMetaData($filePath);

		$newImage = array_merge($existingData, $fileData, $metaData, $colorData);

		if ($objectExists) {
			// If the object existed before, we will keep the existing data
			$newImage = array_merge($newImage, $existingData);
		}

		return $this->updateObject($collection, $objectID, $property, new ImageData($newImage));
	}
}

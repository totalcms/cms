<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\ImageData;

class ImageSaver extends FileSaver
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

		// Only keep certain existing data, but allow EXIF to populate alt and tags if they're empty
		$keep         = ['featured', 'link'];
		$existingData = array_filter($imageProp->transform(), fn ($key): bool => in_array($key, $keep), ARRAY_FILTER_USE_KEY);

		// Keep existing alt and tags only if they have values
		$existingAlt  = trim($imageProp->alt ?? '');
		$existingTags = $imageProp->tags->list ?? [];
		if ($existingAlt !== '') {
			$existingData['alt'] = $existingAlt;
		}
		if (count($existingTags) > 0) {
			$existingData['tags'] = $existingTags;
		}

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

		// Extract EXIF metadata (includes alt text and tags from IPTC/XMP)
		$metaData  = ImageMetaReader::getMetaData($filePath);

		// Merge data with EXIF taking precedence for alt and tags if they're empty in existing data
		$newImage = array_merge($fileData, $metaData, $colorData, $existingData);

		return $this->updateObject($collection, $objectID, $property, new ImageData($newImage));
	}
}

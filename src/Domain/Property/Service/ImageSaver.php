<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\Concerns\DerivesImageData;

class ImageSaver extends FileSaver
{
	use DerivesImageData;

	public string $type = 'image';

	public function rebuildFromStorage(string $collection, string $id, string $property, ?string $subpath = null): ?PropertyData
	{
		$files = $this->storage->listPropertyFiles($collection, $id, $property, $subpath);
		if ($files === []) {
			return null;
		}

		return new ImageData($this->deriveImageData($collection, $id, $property, $files[0], $subpath));
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null,
	): ObjectData {
		$this->assertNotSvg($filePath);

		// Convert HEIC/HEIF to JPEG before anything reads the file, so the stored
		// file, palette and EXIF are all derived from the JPEG — not the HEIC.
		$filePath = $this->convertHeicToJpeg($filePath);

		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		// Clean up existing files at this exact path (top-level or nested). Without
		// the $subpath here, a card-nested image save would wipe siblings.
		$this->storage->deleteDirectory($collection, $objectID, $property, null, $subpath);

		// Update the object with the new file data
		$imageProp = $this->fetchExistingChildProperty($collection, $objectID, $property, $subpath);

		// Only keep certain existing data, but allow EXIF to populate alt and tags if they're empty
		$keep         = ['featured', 'link'];
		$existingData = array_filter($imageProp->transform(), fn ($key): bool => in_array($key, $keep), ARRAY_FILTER_USE_KEY);

		// Keep existing alt and tags only if they have values. ImageData always
		// initializes these, so direct access is safe whether $imageProp came
		// from the object or was freshly built from an empty array.
		$existingAlt  = trim($imageProp->alt ?? '');
		$existingTags = $imageProp->tags->list ?? [];
		if ($existingAlt !== '') {
			$existingData['alt'] = $existingAlt;
		}
		if (count($existingTags) > 0) {
			$existingData['tags'] = $existingTags;
		}

		$fileData = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);

		$metaData = $this->extractImageMetadata($filePath, [
			'collection' => $collection,
			'objectID'   => $objectID,
			'property'   => $property,
		]);

		// Merge data with EXIF taking precedence for alt and tags if they're empty in existing data
		$newImage = array_merge($fileData, $metaData, $existingData);

		return $this->updateObject($collection, $objectID, $property, new ImageData($newImage), $subpath);
	}
}

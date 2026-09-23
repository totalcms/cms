<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\Concerns\DerivesImageData;

class GallerySaver extends FileSaver
{
	use DerivesImageData;

	public string $type = 'gallery';

	public function rebuildFromStorage(string $collection, string $id, string $property, ?string $subpath = null): ?PropertyData
	{
		$files = $this->storage->listPropertyFiles($collection, $id, $property, $subpath);
		if ($files === []) {
			return null;
		}

		// Stable, predictable order (original manual ordering is unrecoverable).
		usort($files, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		$images = array_map(
			fn (array $file): array => $this->deriveImageData($collection, $id, $property, $file, $subpath),
			$files,
		);

		return new GalleryData($images);
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

		$gallery = $this->fetchProperty($collection, $objectID, $property);
		if (!$gallery instanceof GalleryData) {
			throw new \RuntimeException('Expected instance of GalleryData');
		}

		$fileData  = $this->storage->saveFile($collection, $objectID, $property, $filePath);

		$metaData = $this->extractImageMetadata($filePath, [
			'collection' => $collection,
			'objectID'   => $objectID,
			'property'   => $property,
		]);

		$newImage          = array_merge($fileData, $metaData);
		$gallery->images[] = new ImageData($newImage);

		return $this->updateObject($collection, $objectID, $property, $gallery);
	}
}

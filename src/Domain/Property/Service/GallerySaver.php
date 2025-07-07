<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;

final class GallerySaver extends FileSaver
{
	public string $type = 'gallery';

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

		$gallery = $this->fetchProperty($collection, $objectID, $property);
		if (!$gallery instanceof GalleryData) {
			throw new \RuntimeException('Expected instance of GalleryData');
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

		$metaData  = ImageMetaReader::getMetaData($filePath);

		$newImage          = array_merge($fileData, $metaData, $colorData);
		$gallery->images[] = new ImageData($newImage);

		return $this->updateObject($collection, $objectID, $property, $gallery);
	}
}

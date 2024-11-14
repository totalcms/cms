<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Utils\ImageMetaReader;
use TotalCMS\Utils\ImagePaletteGenerator;

final class GallerySaver extends FileSaver
{
	public string $type = 'gallery';

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null
	): ObjectData
	{
		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		$gallery = $this->fetchProperty($collection, $objectID, $property);
		if (!$gallery instanceof GalleryData) {
			throw new \RuntimeException('Expected instance of GalleryData');
		}

		$fileData  = $this->storage->saveFile($collection, $objectID, $property, $filePath);
		$colorData = ['palette' => ImagePaletteGenerator::getPalette($filePath)];
		$metaData  = ImageMetaReader::getMetaData($filePath);

		$newImage = array_merge($fileData, $metaData, $colorData);
		$gallery->images[] = new ImageData($newImage);

		return $this->updateObject($collection, $objectID, $property, $gallery);
	}
}

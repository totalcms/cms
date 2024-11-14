<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\FileData;

final class DepotSaver extends FileSaver
{
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

		$files    = $this->fetchProperty($collection, $objectID, $property)->transform();
		$fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);
		$files[]  = (new FileData($fileinfo))->transform();

		return $this->updateObject($collection, $objectID, $property, $files);
	}
}

<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;

class DepotSaver extends FileSaver
{
	public string $type = 'depot';

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

		$depot = $this->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);

		// Keep the downlaod name as the original file name in case the file was renamed
		$fileinfo['download'] = basename($filePath);

		// Directly find or create the folder in the specified path and add the file
		$depotManager = new DepotPropertyManager($depot);
		$depotManager->addFile(new FileData($fileinfo), $subpath);

		return $this->updateObject($collection, $objectID, $property, $depot);
	}
}

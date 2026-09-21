<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;

class DepotRemover extends FileRemover
{
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

		$depot = $this->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		// Drop the entry from the depot tree and save the record BEFORE touching
		// disk — a refused save (whole-object validation) must leave the file in
		// place rather than a record that names a file that no longer exists.
		$depotManager = new DepotPropertyManager($depot);
		$depotManager->deleteFile($name, $subpath);

		$updated = $this->updateObject($collection, $objectID, $property, $depot->transform());

		$this->storage->deleteDirectory($collection, $objectID, $property, $name, $subpath);
		$this->storage->deleteFile($collection, $objectID, $property, $name, $subpath);

		return $updated;
	}
}

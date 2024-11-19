<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

final class DepotFileMover
{
	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	public function moveFile(
		string $collection,
		string $objectID,
		string $property,
		string $name,
		string $subpath,
		string $destination,
	): bool {
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$depot = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$movedFile = $this->storage->moveFile($collection, $objectID, $property, $name, $subpath, $destination);

		if ($movedFile) {
			// Directly find or create the folder in the specified path and add the file
			$depotManager = new DepotPropertyManager($depot);
			$depotManager->moveFile($name, $subpath, $destination);

			// Update the object with the new file list
			$propertyData = [$property => $depot->transform()];
			$this->objectPatcher->patchObject($collection, $objectID, $propertyData);
		}

		return $movedFile;
	}
}

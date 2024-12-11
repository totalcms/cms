<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

final class DepotFolderSaver
{
	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	public function createFolder(
		string $collection,
		string $objectID,
		string $property,
		string $path,
	): ObjectData {
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$depot = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		// Directly find or create the folder in the specified path
		$depotManager = new DepotPropertyManager($depot);
		$depotManager->createFolder($path);

		// Update the object with the new file list
		$propertyData = [$property => $depot->transform()];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}
}

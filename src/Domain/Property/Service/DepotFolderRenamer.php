<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

class DepotFolderRenamer
{
	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
	) {
	}

	public function renameFolder(
		string $collection,
		string $objectID,
		string $property,
		string $path,
		string $newName,
	): ObjectData {
		if (!$this->objectFetcher->existsObject($collection, $objectID)) {
			throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
		}

		$depot = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$this->storage->renameFolder($collection, $objectID, $property, $path, $newName);

		$depotManager = new DepotPropertyManager($depot);
		$depotManager->renameFolder($path, $newName);

		$propertyData = [$property => $depot->transform()];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}
}

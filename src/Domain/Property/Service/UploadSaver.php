<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Utils\PathUtils;

class UploadSaver
{
	public function __construct(
		private PropertyRepository $storage
	){}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filename,
	): string {
		// Save File
		$this->storage->saveFile($collection, $objectID, $property, $filename);

		return PathUtils::buildPath($collection, $objectID, $property, $filename);
	}
}

<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

class UploadSaver
{
	public function __construct(
		private readonly PropertyRepository $storage,
	) {
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filename,
	): string {
		// Save File
		$file = $this->storage->saveFile($collection, $objectID, $property, $filename);

		return PathUtils::buildPath($collection, $objectID, $property, (string)$file['name']);
	}
}

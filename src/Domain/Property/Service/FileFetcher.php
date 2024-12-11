<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

final class FileFetcher
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher
	) {}

	public function fetchFile(string $collection, string $id, string $property): FileData
	{
		$file = $this->propFetcher->fetchProperty($collection, $id, $property);

		if (!$file instanceof FileData) {
			throw new \RuntimeException('Unable to reteive file data');
		}

		return $file;
	}

	public function fileExists(string $collection, string $id, string $property): bool
	{
		$file = $this->fetchFile($collection, $id, $property);

		return $this->storage->fileExists($collection, $id, $property, $file->name);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property)
	{
		$file = $this->fetchFile($collection, $id, $property);

		return $this->storage->streamFile($collection, $id, $property, $file->name);
	}
}

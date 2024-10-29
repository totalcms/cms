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

		return new FileData($file->transform());
	}

	public function fileExists(string $collection, string $id, string $property): bool
	{
		$file = $this->fetchFile($collection, $id, $property);

		if (empty($file->filename)) {
			return false;
		}

		return $this->storage->fileExists($collection, $id, $property, $file->filename);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property)
	{
		$file = $this->fetchFile($collection, $id, $property);

		if (empty($file->filename)) {
			throw new \RuntimeException('File not found');
		}

		return $this->storage->streamFile($collection, $id, $property, $file->filename);
	}
}

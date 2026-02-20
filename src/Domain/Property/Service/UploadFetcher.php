<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class UploadFetcher
{
	public function __construct(
		private PropertyRepository $storage,
	) {
	}

	public function fileExists(string $collection, string $id, string $property, string $name): bool
	{
		return $this->storage->fileExists($collection, $id, $property, $name);
	}

	public function mimeType(string $collection, string $id, string $property, string $name): string
	{
		return $this->storage->mimeType($collection, $id, $property, $name);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property, string $name)
	{
		return $this->storage->streamFile($collection, $id, $property, $name);
	}

	public function fileSize(string $collection, string $id, string $property, string $name): int
	{
		return $this->storage->fileSize($collection, $id, $property, $name);
	}

	/** @return array<int, array{name: string, path: string}> */
	public function listFiles(string $collection, string $id, string $property): array
	{
		return $this->storage->listPropertyFiles($collection, $id, $property);
	}
}

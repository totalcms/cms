<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class UploadFetcher
{
	public function __construct(
		private PropertyRepository $storage,
	) {
	}

	public function fileExists(string $collection, string $id, string $property, string $name, ?string $subpath = null): bool
	{
		return $this->storage->fileExists($collection, $id, $property, $name, $subpath);
	}

	public function mimeType(string $collection, string $id, string $property, string $name, ?string $subpath = null): string
	{
		return $this->storage->mimeType($collection, $id, $property, $name, $subpath);
	}

	/** @return resource */
	public function streamFile(string $collection, string $id, string $property, string $name, ?string $subpath = null)
	{
		return $this->storage->streamFile($collection, $id, $property, $name, $subpath);
	}

	public function fileSize(string $collection, string $id, string $property, string $name, ?string $subpath = null): int
	{
		return $this->storage->fileSize($collection, $id, $property, $name, $subpath);
	}

	/** @return array<int, array{name: string, path: string}> */
	public function listFiles(string $collection, string $id, string $property, ?string $subpath = null): array
	{
		return $this->storage->listPropertyFiles($collection, $id, $property, $subpath);
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;

readonly class UploadRemover
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyCacheCleaner $cacheCleaner,
	) {
	}

	public function deleteFile(string $collection, string $id, string $property, string $name, ?string $subpath = null): bool
	{
		$this->storage->deleteFile($collection, $id, $property, $name, $subpath);
		$this->cacheCleaner->deleteFileCache($collection, $id, $property, $name, $subpath);

		return $this->storage->fileExists($collection, $id, $property, $name, $subpath);
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;

/**
 * Service.
 */
readonly class PropertyCacheCleaner
{
	public function __construct(private PropertyRepository $storage)
	{
	}

	public function deletePropertyCache(string $collection, string $objectID, string $property): bool
	{
		return $this->storage->deletePropertyCache($collection, $objectID, $property);
	}

	public function deleteFileCache(string $collection, string $objectID, string $property, string $name, ?string $subpath = null): bool
	{
		return $this->storage->deleteFileCache($collection, $objectID, $property, $name, $subpath);
	}
}

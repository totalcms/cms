<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Repository\PropertyRepository;

/**
 * Service.
 */
final class PropertyCacheCleaner
{
    public function __construct(private PropertyRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Delete property cache.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     */
    public function deletePropertyCache(string $collection, string $objectID, string $property): bool
    {
        return $this->storage->deletePropertyCache($collection, $objectID, $property);
    }
}

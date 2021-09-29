<?php

namespace App\Domain\Collection\Service;

use App\Domain\Storage\CollectionStorage;

/**
 * Service.
 */
final class CollectionFinder
{
    private CollectionStorage $storage;

    public function __construct(CollectionStorage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List all collections.
     *
     * @return array<object>
     */
    public function listAllCollections(): array
    {
        return $this->storage->listAllCollections();
    }
}

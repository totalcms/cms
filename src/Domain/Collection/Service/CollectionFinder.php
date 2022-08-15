<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Repository\CollectionRepository;

/**
 * Service.
 */
final class CollectionFinder
{
    private CollectionRepository $storage;

    public function __construct(CollectionRepository $storage)
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

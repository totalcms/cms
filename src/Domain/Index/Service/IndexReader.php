<?php

namespace App\Domain\Index\Service;

use App\Domain\Index\Data\IndexData;
use App\Domain\Index\Repository\IndexRepository;
use DomainException;

/**
 * Service.
 */
final class IndexReader
{
    private IndexRepository $storage;

    public function __construct(IndexRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Fetch a collection index.
     *
     * @param string $collection
     *
     * @return array
     */
    public function fetchIndex(string $collection): array
    {
        $index = $this->storage->fetchIndex($collection);

        if (!$index instanceof IndexData) {
            throw new DomainException('Unknown error fetching index.');
        }

        return $index->toArray();
    }
}

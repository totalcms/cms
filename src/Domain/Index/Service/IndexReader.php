<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;

/**
 * Service.
 */
final class IndexReader
{
    private IndexRepository $storage;
    private IndexBuilder $builder;

    public function __construct(IndexRepository $storage, IndexBuilder $builder)
    {
        $this->storage = $storage;
        $this->builder = $builder;
    }

    /**
     * Fetch a collection index.
     *
     * @param string $collection
     *
     * @return IndexData
     */
    public function fetchIndex(string $collection): IndexData
    {
        $index = $this->storage->fetchIndex($collection);

        if (!$index instanceof IndexData) {
            // Build the index if it does not exist
            return $this->builder->buildIndex($collection);
        }

        return $index;
    }
}

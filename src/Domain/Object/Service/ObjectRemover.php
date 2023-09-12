<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Service.
 */
final class ObjectRemover
{
    private ObjectRepository $storage;
    private IndexBuilder $indexBuilder;

    public function __construct(ObjectRepository $storage, IndexBuilder $indexBuilder)
    {
        $this->storage      = $storage;
        $this->indexBuilder = $indexBuilder;
    }

    /**
     * delete a collection object.
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteObject(string $collection, string $id): bool
    {
        $status = $this->storage->deleteObject($collection, $id);

        if ($status) {
            $this->indexBuilder->buildIndex($collection);
        }

        return $status;
    }
}

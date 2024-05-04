<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;

/**
 * Service.
 */
final class IndexBuilder
{
    private IndexRepository $storage;
    private ObjectFetcher $objectFetcher;
    private CollectionSchemaFetcher $schemaFetcher;

    public function __construct(IndexRepository $storage, ObjectFetcher $objectFetcher, CollectionSchemaFetcher $schemaFetcher)
    {
        $this->storage       = $storage;
        $this->objectFetcher = $objectFetcher;
        $this->schemaFetcher = $schemaFetcher;
    }

    /**
     * Save Index data.
     *
     * @param string $collection The collection
     *
     * @return IndexData
     */
    public function buildIndex(string $collection): IndexData
    {
        $objectIds  = $this->storage->fetchObjectIds($collection);
        $schema     = $this->schemaFetcher->fetchSchemaForCollection($collection);
        $indexProps = $schema->index;
        $index      = new IndexData();

        foreach ($objectIds as $id) {
            $object  = $this->objectFetcher->fetchObject($collection, $id);
            // The reject method is used to filter out properties that are not in the index
            // The map method is used to transform the properties into an array
            $summary = $object->properties
                ->reject(fn ($value, $key) => !in_array($key, $indexProps, true))
                ->map(fn ($property) => $property->transform());
            $summary->put('id', $id);
            $index->objects->push($summary->toArray());
        }

        $this->storage->saveIndex($collection, $index);

        return $index;
    }
}

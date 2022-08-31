<?php

namespace App\Domain\Index\Service;

use App\Domain\Index\Data\IndexData;
use App\Domain\Index\Repository\IndexRepository;
use App\Domain\Object\Service\ObjectFetcher;
use App\Domain\Schema\Service\SchemaFetcher;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Service.
 */
final class IndexBuilder
{
    private IndexRepository $storage;
    private Serializer $serializer;
    private ObjectFetcher $objectFetcher;
    private SchemaFetcher $schemaFetcher;

    public function __construct(IndexRepository $storage, ObjectFetcher $objectFetcher, SchemaFetcher $schemaFetcher)
    {
        $this->storage       = $storage;
        $this->serializer    = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
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
        $indexProps = $schema->schema['index'];
        $index      = new IndexData();

        foreach ($objectIds as $id) {
            $object  = $this->objectFetcher->fetchObject($collection, $id);
            $summary = $object->properties->reject(fn ($value, $key) => !in_array($key, $indexProps, true));
            $summary->put('id', $id);
            $index->objects->push($summary->toArray());
        }

        $this->storage->saveIndex($collection, $index);

        return $index;
    }
}

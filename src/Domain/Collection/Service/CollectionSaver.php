<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

/**
 * Service.
 */
final class CollectionSaver
{
    private CollectionRepository $storage;
    private IndexBuilder $indexBuilder;
    private SchemaValidator $validator;
    private CollectionFactory $factory;

    public function __construct(CollectionRepository $storage, IndexBuilder $indexBuilder, CollectionFactory $factory, SchemaValidator $validator)
    {
        $this->storage      = $storage;
        $this->indexBuilder = $indexBuilder;
        $this->factory      = $factory;
        $this->validator    = $validator;
    }

    /**
     * Save Collection data.
     *
     * @param string $json The collection data to save. This should be json encoded.
     *
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function saveCollection(string $json): CollectionData
    {
        $collection = $this->factory->generateCollection($json);

        // Verify Schema Exists and is Valid

        if ($this->validator->validateSchema($collection->toJson(), 'meta') === false) {
            throw new \UnexpectedValueException('Invalid Collection data provided. Failed schema validation.', 1);
        }

        $this->storage->saveCollection($collection);

        $this->indexBuilder->buildIndex($collection->id);

        return $collection;
    }
}

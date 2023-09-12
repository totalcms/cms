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
     * @param array $data
     * @param bool $update
     *
     * @throws \DomainException
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function saveCollection(array $data): CollectionData
    {
        $collection = $this->factory->generateCollection($data);

        if ($this->storage->collectionExists($collection->id)) {
            throw new \DomainException(sprintf('Collection with id %s already exists', $collection->id));
        }

        if ($this->validator->validateSchema($collection->toJson(), 'meta') === false) {
            throw new \UnexpectedValueException('Invalid Collection data provided. Failed schema validation.', 1);
        }

        $this->storage->saveCollection($collection);

        return $collection;
    }

    /**
     * update Collection data.
     *
     * @param string $collectionId
     * @param array $data The collection data to save
     *
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function updateCollection(string $collectionId, array $data): CollectionData
    {
        $collection = $this->factory->generateCollection($data);

        if ($collection->id !== $collectionId) {
            throw new \UnexpectedValueException('Invalid Collection data provided. Does not match collection ID.', 1);
        }

        $this->storage->saveCollection($collection);

        return $collection;
    }

    /**
     * update Collection data.
     *
     * @param string $collectionId
     * @param array $patch The collection data to patch
     *
     * @throws \UnexpectedValueException
     *
     * @return CollectionData
     */
    public function patchCollection(string $collectionId, array $patch): CollectionData
    {
        $collection = $this->storage->fetchCollection($collectionId);

        $mergedCollection = array_merge($collection->toArray(), $patch);

        return $this->updateCollection($collectionId, $mergedCollection);
    }
}

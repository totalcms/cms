<?php

namespace App\Domain\Object\Service;

use App\Domain\Collection\Service\CollectionFetchService;
use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Repository\ObjectRepository;
use App\Interfaces\ServiceInterface;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectSaveService implements ServiceInterface
{
    private ObjectRepository $repository;
    private CollectionFetchService $collectionService;
    private Serializer $serializer;

    /**
     * Constructor.
     *
     * @param ObjectRepository $repository The repository
     */
    public function __construct(ObjectRepository $repository, CollectionFetchService $collectionService)
    {
        $this->repository        = $repository;
        $this->collectionService = $collectionService;
        $this->serializer        = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * save a collection object
     *
     * @param string $collection
     * @param string $objectJSON
     *
     * @return ObjectData
     */
    public function saveObject(string $collection, string $objectJSON): ObjectData
    {
        $collection = $this->collectionService->fetchCollection($collection);

        $object = (object)$this->serializer->deserialize($objectJSON, ObjectData::class, 'json');
        if (!($object instanceof ObjectData)) {
            throw new UnexpectedValueException('Invalid object data provided', 1);
        }

        if (!$this->repository->saveObject($collection->name, $object)) {
            throw new RuntimeException('Unable to save object', 1);
        }
        return $object;
    }
}

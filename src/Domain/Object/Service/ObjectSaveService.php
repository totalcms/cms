<?php

namespace App\Domain\Object\Service;

use App\Domain\Collection\Service\CollectionFetchService;
use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Repository\ObjectRepository;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class ObjectSaveService
{
    private ObjectRepository $repository;
    private CollectionFetchService $collectionService;
    private Serializer $serializer;

    /**
     * Constructor.
     *
     * @param ObjectRepository $repository The repository
     * @param CollectionFetchService $collectionService
     */
    public function __construct(ObjectRepository $repository, CollectionFetchService $collectionService)
    {
        $this->repository = $repository;
        $this->collectionService = $collectionService;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * save a collection object.
     *
     * @param string $collection
     * @param string $objectJSON
     *
     * @throws UnexpectedValueException
     * @throws RuntimeException
     *
     * @return ObjectData
     */
    public function saveObject(string $collection, string $objectJSON): ObjectData
    {
        $collection = $this->collectionService->fetchCollection($collection);

        $object = $this->serializer->deserialize($objectJSON, ObjectData::class, 'json');
        if (!$object instanceof ObjectData) {
            throw new UnexpectedValueException('Invalid object data provided');
        }

        $this->repository->saveObject($collection->name, $object);

        return $object;
    }
}

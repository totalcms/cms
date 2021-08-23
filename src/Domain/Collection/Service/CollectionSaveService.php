<?php

namespace App\Domain\Collection\Service;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Collection\Repository\CollectionRepository;
use App\Interfaces\ServiceInterface;
use Exception;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Service.
 */
final class CollectionSaveService implements ServiceInterface
{
    private CollectionRepository $repository;
    private Serializer $serializer;

    /**
     * Constructor.
     *
     * @param CollectionRepository $repository The repository
     */
    public function __construct(CollectionRepository $repository)
    {
        $this->repository = $repository;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * Save Collection data.
     *
     * @param string $data the collection data to save
     *
     * @return CollectionData
     */
    public function saveCollection(string $data): CollectionData
    {
        $collection = (object)$this->serializer->deserialize($data, CollectionData::class, 'json');
        if (!is_a($collection, CollectionData::class, false)) {
            throw new Exception('Invalid Collection data provided', 1);
        }
        if (!$this->repository->saveCollection($collection)) {
            throw new Exception('Unable to save Collection', 1);
        }

        return $collection;
    }
}

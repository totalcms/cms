<?php

namespace App\Domain\Object\Repository;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Storage\StorageRepository;

/**
 * Repository.
 */
final class ObjectRepository extends StorageRepository
{
    /**
     * Save an object.
     *
     * @param string $collection
     * @param ObjectData $object
     *
     * @return void
     */
    public function saveObject(string $collection, ObjectData $object): void
    {
        $objectFile = $this->buildObjectPath($collection, $object->id);
        $objectJSON = $this->serializer->serialize($object, 'json');

        $this->filesystem->write($objectFile, $objectJSON);
    }

    public function existsObjectId(string $collection, string $id): bool
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        return $this->filesystem->fileExists($objectFile);
    }

    public function fetchObject(string $collection, string $id): ObjectData
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        $contents = $this->filesystem->read($objectFile);
        $schema   = $this->serializer->deserialize($contents, ObjectData::class, 'json');

        if ($schema instanceof ObjectData) {
            return $schema;
        }

        return new ObjectData($id, []);
    }

    private function buildObjectPath(string $collection, string $id): string
    {
        return sprintf('%s/%s%s', $this->cleanString($collection), $this->cleanString($id), self::FILE_EXT);
    }
}

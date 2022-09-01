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
        $objectJSON = $object->toJson();

        $this->filesystem->write($objectFile, $objectJSON);
    }

    public function existsObject(string $collection, string $id): bool
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        return $this->filesystem->fileExists($objectFile);
    }

    public function fetchObject(string $collection, string $id): ?ObjectData
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        if ($this->filesystem->fileExists($objectFile)) {
            $contents = $this->filesystem->read($objectFile);
            $schema   = $this->serializer->deserialize($contents, ObjectData::class, 'json');

            if ($schema instanceof ObjectData) {
                return $schema;
            }
        }

        return null;
    }

    public function deleteObject(string $collection, string $id): bool
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        return $this->filesystem->delete($objectFile);
    }

    private function buildObjectPath(string $collection, string $id): string
    {
        return sprintf('%s/%s%s', $this->cleanString($collection), $this->cleanString($id), self::FILE_EXT);
    }
}

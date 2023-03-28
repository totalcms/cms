<?php

namespace TotalCMS\Domain\Object\Repository;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

/**
 * Repository.
 */
final class ObjectRepository extends StorageRepository
{
    private ObjectFactory $factory;

    /**
     * The constructor.
     *
     * @param StorageFilesystemAdapter $filesystem The filesystem factory
     * @param ObjectFactory $objectFactory
     */
    public function __construct(StorageAdapterInterface $filesystem, ObjectFactory $objectFactory)
    {
        parent::__construct($filesystem);

        $this->factory = $objectFactory;
    }

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
            $object   = $this->factory->generateObject($collection, $contents);

            if ($object instanceof ObjectData) {
                return $object;
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
        return PathUtils::buildPath(collection: $collection, filename: $id . self::FILE_EXT);
    }
}

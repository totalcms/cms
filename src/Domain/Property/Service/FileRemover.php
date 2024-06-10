<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

/**
 * Service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class FileRemover
{
    public function __construct(
        private PropertyRepository $storage,
        private PropertyFetcher $propFetcher,
        private ObjectSaver $objectSaver,
        private ObjectFetcher $objectFetcher,
    ) {
    }

    private function fetchProperty(string $collection, string $objectID, string $property): PropertyData
    {
        // Get the existing object property data
        $fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

        if (!$fileProperty instanceof PropertyData) {
            throw new \UnexpectedValueException('Invalid file property found');
        }

        return $fileProperty;
    }

    private function updateObject(string $collection, string $objectID, string $property, array $data): ObjectData
    {
        $propertyData = [$property => $data];

        return $this->objectSaver->patchObject($collection, $objectID, $propertyData);
    }

    public function deleteFile(string $collection, string $objectID, string $property, string $filename): ObjectData
    {
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            throw new \UnexpectedValueException("Object $objectID does not exist in $collection");
        }

        $this->storage->deleteFile($collection, $objectID, $property, $filename);

        $files = $this->fetchProperty($collection, $objectID, $property)->transform();
        foreach ($files as $key => $file) {
            if ($file['name'] === $filename) {
                unset($files[$key]);
                break;
            }
        }

        // Reindex the array
        $files = array_values($files);

        return $this->updateObject($collection, $objectID, $property, $files);
    }
}

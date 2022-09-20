<?php

namespace App\Domain\Property\Service;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Object\Service\ObjectFetcher;
use App\Domain\Object\Service\ObjectUpdater;
use App\Domain\Property\Data\FileData;
use App\Domain\Property\Data\PropertyData;
use App\Domain\Property\Repository\PropertyRepository;
use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Storage\StorageRepository;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use UnexpectedValueException;

/**
 * Service.
 */
final class FileSaver
{
    private Serializer $serializer;

    public function __construct(
        private PropertyRepository $storage,
        private PropertyFetcher $propFetcher,
        private ObjectUpdater $objectUpdater,
        private SchemaFetcher $schemaFetcher,
        private ObjectFetcher $objectFetcher,
    ) {
        $this->storage       = $storage;
        $this->propFetcher   = $propFetcher;
        $this->objectUpdater = $objectUpdater;
        $this->schemaFetcher = $schemaFetcher;
        $this->objectFetcher = $objectFetcher;
        $this->serializer    = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * save a file to collection object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFile(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        $schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
        $type   = basename($schema->schema['properties'][$property]['$ref'], StorageRepository::FILE_EXT);

        $method = 'saveFileFor' . ucfirst($type);

        if (!method_exists($this, $method)) {
            throw new UnexpectedValueException('Invalid file type found');
        }

        return $this->$method($collection, $objectID, $property, $filePath);
    }

    /**
     * fetch property data.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     *
     * @return PropertyData
     */
    private function fetchProperty(string $collection, string $objectID, string $property): PropertyData
    {
        // Get the existing object property data
        $fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

        if (!$fileProperty instanceof PropertyData) {
            throw new UnexpectedValueException('Invalid file property found');
        }

        return $fileProperty;
    }

    /**
     * Update the object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param array $data
     *
     * @return ObjectData
     */
    private function updateObject(string $collection, string $objectID, string $property, array $data): ObjectData
    {
        $propertyJson = $this->serializer->serialize([$property => $data], 'json');

        return $this->objectUpdater->updateObject($collection, $objectID, $propertyJson);
    }

    /**
     * save a file to a file property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForFile(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            throw new UnexpectedValueException('Object does not exist');
        }

        // Clean up existing files in the path. Only one file should exist
        $this->storage->deleteDirectory($collection, $objectID, $property);

        // Update the object with the new file data
        $fileProperty = $this->fetchProperty($collection, $objectID, $property);
        $fileInfo     = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $newData      = array_merge($fileProperty->transform(), $fileInfo);

        return $this->updateObject($collection, $objectID, $property, $newData);
    }

    /**
     * save a file to depot property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForDepot(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            throw new UnexpectedValueException('Object does not exist');
        }

        $files    = $this->fetchProperty($collection, $objectID, $property)->transform();
        $fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $files[]  = (new FileData($property, $fileinfo))->transform();

        return $this->updateObject($collection, $objectID, $property, $files);
    }
}

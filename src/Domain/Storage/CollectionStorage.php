<?php

namespace App\Domain\Storage;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Schema\Data\SchemaData;
use Cocur\Slugify\Slugify;
use DomainException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Repository.
 */
final class CollectionStorage
{
    private StorageAdapterInterface $filesystem;
    private Serializer $serializer;

    private const META_FILE = '.meta.json';
    private const SCHEMA_FILE = '.schema.json';
    private const OBJECT_EXT = '.json';

    /**
     * The constructor.
     *
     * @param StorageFilesystemAdapter $filesystem The filesystem factory
     */
    public function __construct(StorageAdapterInterface $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * fetch and deserialize a file.
     *
     * @template CLASS of object
     *
     * @param string $file
     * @param class-string<CLASS> $className
     *
     * @return CLASS|null
     */
    private function fetchAndDeserialize(string $file, string $className): ?object
    {
        $contents = null;

        if ($this->filesystem->fileExists($file)) {
            $contents = $this->filesystem->read($file);
        }

        if (empty($contents)) {
            return null;
        }

        $collection = $this->serializer->deserialize($contents, $className, 'json');
        if ($collection instanceof $className) {
            return $collection;
        }

        return null;
    }

    /**
     * List all Collections.
     *
     * @return array<CollectionData>
     */
    public function listAllCollections(): array
    {
        $collections = [];
        foreach ($this->filesystem->listDirectories('') as $name) {
            $collection = $this->fetchCollection($name);
            if ($collection == null) {
                continue;
            }
            $collections[] = $collection;
        }

        return $collections;
    }

    /**
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @return ?CollectionData
     */
    public function fetchCollection(string $collection): ?CollectionData
    {
        $metaFile = $collection . DIRECTORY_SEPARATOR . self::META_FILE;

        return $this->fetchAndDeserialize($metaFile, CollectionData::class);
    }

    /**
     * Fetch a collection.
     *
     * @param string $collection
     *
     * @throws DomainException
     *
     * @return CollectionData
     */
    public function getCollection(string $collection): CollectionData
    {
        $collection = $this->fetchCollection($collection);

        if ($collection === null) {
            throw new DomainException(sprintf('Collection does not exist: %s', $collection));
        }

        return $collection;
    }

    /**
     * Save a Collection.
     *
     * @param CollectionData $collection the collection to save
     *
     * @return void
     */
    public function saveCollection(CollectionData $collection): void
    {
        $jsonContent = $this->serializer->serialize($collection, 'json');
        $metaFile = $collection->name . DIRECTORY_SEPARATOR . self::META_FILE;

        $this->filesystem->write($metaFile, $jsonContent);
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $type
     *
     * @return ?SchemaData
     */
    public function fetchDefaultSchemaForType(string $type): ?SchemaData
    {
        // TODO: Refactor - this need to be extracted into its own default schema class or something
        $schemaFile = __DIR__ . "/../../../schemas/$type.json";
        if (!file_exists($schemaFile)) {
            return null;
        }

        $contents = (string)file_get_contents($schemaFile);
        $schema = $this->serializer->deserialize($contents, SchemaData::class, 'json');

        if ($schema instanceof SchemaData) {
            return $schema;
        }

        return null;
    }

    /**
     * fetch a schema for one of the default schema types.
     *
     * @param string $type
     *
     * @return SchemaData
     */
    public function getDefaultSchemaForType(string $type): SchemaData
    {
        $schema = $this->fetchDefaultSchemaForType($type);

        if ($schema === null) {
            throw new DomainException(sprintf('Type does not exist: %s', $type));
        }

        return $schema;
    }

    /**
     * fetch a schema for a custom object.
     *
     * @param string $collection
     *
     * @return ?SchemaData
     */
    public function fetchObjectSchemaForCollection(string $collection): ?SchemaData
    {
        $schemaFile = $collection . DIRECTORY_SEPARATOR . self::SCHEMA_FILE;

        if (!$this->filesystem->fileExists($schemaFile)) {
            return null;
        }

        $contents = $this->filesystem->read($schemaFile);
        $schema = $this->serializer->deserialize($contents, SchemaData::class, 'json');

        if ($schema instanceof SchemaData) {
            return $schema;
        }

        return null;
    }

    /**
     * Get a schema for a custom object.
     *
     * @param string $collection
     *
     * @return SchemaData
     */
    public function getObjectSchemaForCollection(string $collection): SchemaData
    {
        $schema = $this->fetchObjectSchemaForCollection($collection);

        if ($schema === null) {
            throw new DomainException(sprintf('Collection does not exist: %s', $collection));
        }

        return $schema;
    }

    /**
     * save a collection schema.
     *
     * @param string $collection
     * @param SchemaData $schema
     *
     * @return void
     */
    public function saveSchemaForCollection(string $collection, SchemaData $schema): void
    {
        $schemaFile = $collection . DIRECTORY_SEPARATOR . self::SCHEMA_FILE;
        $schemaJSON = $this->serializer->serialize($schema, 'json');

        $this->filesystem->write($schemaFile, $schemaJSON);
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
        $objectJSON = $this->serializer->serialize($object, 'json');

        $this->filesystem->write($objectFile, $objectJSON);
    }

    public function existsObjectId(string $collection, string $id): bool
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        return $this->filesystem->fileExists($objectFile);
    }

    public function getObject(string $collection, string $id): ObjectData
    {
        $objectFile = $this->buildObjectPath($collection, $id);

        $contents = $this->filesystem->read($objectFile);
        $schema = $this->serializer->deserialize($contents, ObjectData::class, 'json');

        if ($schema instanceof ObjectData) {
            return $schema;
        }

        return new ObjectData($schema);
    }

    private function cleanString(string $string): string
    {
        return (new Slugify())->slugify($string);
    }

    private function buildObjectPath(string $collection, string $id): string
    {
        return sprintf('%s/%s%s', $collection, $this->cleanString($id), self::OBJECT_EXT);
    }
}

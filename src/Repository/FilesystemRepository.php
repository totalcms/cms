<?php

namespace App\Repository;

use App\Domain\Collection\Data\CollectionData;
use App\Domain\Schema\Data\SchemaData;
use App\Factory\FilesystemIteratorFactory;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Repository.
 */
class FilesystemRepository implements RepositoryInterface
{
    private FilesystemIteratorFactory $filesystem;
    private Serializer $serializer;

    const META_FILE   = '.meta.json';
    const SCHEMA_FILE = '.schema.json';

    /**
     * Constructor.
     *
     * @param FilesystemIteratorFactory $filesystem The filesystem factory
     */
    public function __construct(FilesystemIteratorFactory $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    /**
     * fetch and deserialize a file
     *
     * @template CLASS
     *
     * @param string              $file
     * @param class-string<CLASS> $className
     *
     * @return CLASS|null
     */
    private function fetchAndDeserialize(string $file, string $className) : ?object
    {
        if ($this->filesystem->exists($file)) {
            $contents = $this->filesystem->readFile($file);
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
     * List all Collections
     *
     * @return array<CollectionData>
     */
    public function listAllCollections() : array
    {
        $collections = [];
        foreach ($this->filesystem->listDirs() as $name) {
            $collection = $this->fetchCollection($name);
            if ($collection == null) {
                continue;
            }
            $collections[] = $collection;
        }
        return $collections;
    }

    /**
     * Fetch a collection
     *
     * @param string $collection
     *
     * @return ?CollectionData
     */
    public function fetchCollection(string $collection) : ?CollectionData
    {
        $metaFile = $collection . DIRECTORY_SEPARATOR . $this::META_FILE;
        return $this->fetchAndDeserialize($metaFile, CollectionData::class);
    }

    /**
     * Save a Collection
     *
     * @param CollectionData $collection the collection to save
     *
     * @return bool
     */
    public function saveCollection(CollectionData $collection) : bool
    {
        $jsonContent = $this->serializer->serialize($collection, 'json');
        $metaFile    = $collection->name . DIRECTORY_SEPARATOR . $this::META_FILE;
        return $this->filesystem->saveFile($metaFile, $jsonContent);
    }

    /**
     * fetch a schema for one of the defaul schema types
     *
     * @param string $type
     *
     * @return ?SchemaData
     */
    public function fetchDefaultSchemaForType(string $type) : ?SchemaData
    {
        // TODO: Refactor - this need to be extracted into its own default schema class or something
        $schemaFile = __DIR__ . "/../../schemas/$type.json";
        if (!file_exists($schemaFile)) {
            return null;
        }
        $contents = file_get_contents($schemaFile);
        if (empty($contents)) {
            return null;
        }
        $schema   = json_decode($contents);
        // TODO: static access to class is a no-no in phpmd
        return is_array($schema) ? SchemaData::fromArray($schema) : null;
    }

    /**
     * fetch a schema for a custom object
     *
     * @param string $collection
     *
     * @return ?SchemaData
     */
    public function fetchObjectSchemaForCollection(string $collection) : ?SchemaData
    {
        $schemaFile = $collection . DIRECTORY_SEPARATOR . $this::SCHEMA_FILE;
        if ($this->filesystem->exists($schemaFile)) {
            $contents = $this->filesystem->readFile($schemaFile);
        }
        $schema = json_decode($contents ?? '');

        // TODO: static access to class is a no-no in phpmd
        return is_array($schema) ? SchemaData::fromArray($schema) : null;
    }
}

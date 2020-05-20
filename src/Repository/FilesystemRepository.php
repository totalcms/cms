<?php

namespace App\Repository;

use App\Domain\Collection\Data\CollectionData;
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

    const META_FILE = '.meta.json';

    /**
     * Constructor.
     *
     * @param FilesystemIteratorFactory $filesystem The filesystem factory
     */
    public function __construct(FilesystemIteratorFactory $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * List all Collections
     *
     * @return array<object>
     */
    public function listAllCollections() : array
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $collections = [];
        foreach ($this->filesystem->listDirs() as $name) {
            $metaFile = $name . DIRECTORY_SEPARATOR . $this::META_FILE;
            if (!$this->filesystem->exists($metaFile)) {
                continue;
            }

            $contents = $this->filesystem->readFile($metaFile);
            if (empty($contents)) {
                continue;
            }

            $collection = $serializer->deserialize($contents, CollectionData::class, 'json');
            if (!(is_object($collection) && is_a($collection, CollectionData::class))) {
                continue;
            }

            $collections[] = $collection;
        }

        return $collections;
    }
}

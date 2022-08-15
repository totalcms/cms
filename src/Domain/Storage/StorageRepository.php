<?php

namespace App\Domain\Storage;

use Cocur\Slugify\Slugify;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Repository.
 */
abstract class StorageRepository
{
    protected StorageAdapterInterface $filesystem;
    protected Serializer $serializer;

    protected const FILE_EXT = '.json';

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
     * @param string<CLASS> $className
     *
     * @return CLASS|null
     */
    protected function fetchAndDeserialize(string $file, string $className): ?object
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

    protected function cleanString(string $string): string
    {
        return (new Slugify())->slugify($string);
    }
}

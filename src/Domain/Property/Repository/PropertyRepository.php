<?php

namespace TotalCMS\Domain\Property\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

/**
 * Repository.
 */
final class PropertyRepository extends StorageRepository
{
    /**
     * @param string $collection
     * @param string $objectID
     * @param string $property
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    public function deleteDirectory(string $collection, string $objectID, string $property): void
    {
        $path = PathUtils::buildPath($collection, $objectID, $property);

        try {
            $this->filesystem->deleteDirectory($path);
        } catch (\Exception $exception) {
            throw new \RuntimeException('Unable to delete directory');
        }
    }

    public function deletePropertyCache(string $collection, string $objectID, string $property): bool
    {
        $path = PathUtils::buildPath($collection, $objectID, $property, '.cache');

        try {
            $this->filesystem->deleteDirectory($path);
        } catch (\Exception $exception) {
            throw new \RuntimeException('Unable to delete cache directory');
        }

        return !$this->filesystem->fileExists($path);
    }

    public function deleteFileCache(string $collection, string $objectID, string $property, string $filename): bool
    {
        $path = PathUtils::buildPath($collection, $objectID, $property, ".cache/$filename");

        try {
            $this->filesystem->deleteDirectory($path);
        } catch (\Exception $exception) {
            throw new \RuntimeException('Unable to delete cache directory');
        }

        return !$this->filesystem->fileExists($path);
    }

    public function deleteFile(string $collection, string $objectID, string $property, string $filename): void
    {
        $path = PathUtils::buildPath($collection, $objectID, $property, $filename);

        try {
            $this->filesystem->delete($path);
        } catch (\Exception $exception) {
            throw new \RuntimeException("Unable to delete file $filename");
        }
        $this->deleteFileCache($collection, $objectID, $property, $filename);
    }

    /**
     * Save file to an object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @throws \RuntimeException
     *
     * @return array<string,string|int>
     */
    public function saveFile(string $collection, string $objectID, string $property, string $filePath): array
    {
        $filename = basename($filePath);
        $newpath  = PathUtils::buildPath($collection, $objectID, $property, $filename);

        // File already exists, rename it
        if ($this->filesystem->fileExists($newpath)) {
            $newname  = self::getUniqueFilename($filename);
            $newpath  = PathUtils::buildPath($collection, $objectID, $property, $newname);
        }

        if (!$this->filesystem->import($filePath, $newpath)) {
            throw new \RuntimeException('File not saved');
        }

        return [
            'name'       => basename($newpath),
            'size'       => $this->filesystem->fileSize($newpath),
            'mime'       => $this->filesystem->mimeType($newpath),
            'uploadDate' => date('c'),
        ];
    }

    /**
     * Save an image to object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @throws \RuntimeException
     *
     * @return array<string,string|int>
     */
    public function saveImage(string $collection, string $objectID, string $property, string $filePath): array
    {
        $filename = basename($filePath);
        $newpath  = PathUtils::buildPath($collection, $objectID, $property, $filename);

        $data = getimagesize($filePath);

        if ($data === false) {
            throw new \RuntimeException('Unable to process image file');
        }

        [$width, $height] = $data;

        if (!$this->filesystem->import($filePath, $newpath)) {
            throw new \RuntimeException('File not saved');
        }

        // Update to be Image data
        return [
            'name'       => basename($newpath),
            'size'       => intval($this->filesystem->fileSize($newpath)),
            'mime'       => $this->filesystem->mimeType($newpath),
            'width'      => $width,
            'height'     => $height,
            'uploadDate' => date('c'),
        ];
    }

    private static function getUniqueFilename(string $filename): string
    {
        $parts = pathinfo($filename);
        $ext   = isset($parts['extension']) ? '.' . $parts['extension'] : '';

        return sprintf('%s-%s%s', $parts['filename'], uniqid(), $ext);
    }
}

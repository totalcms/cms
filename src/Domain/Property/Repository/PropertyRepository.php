<?php

namespace App\Domain\Property\Repository;

use App\Domain\Storage\StorageRepository;
use RuntimeException;

/**
 * Repository.
 */
final class PropertyRepository extends StorageRepository
{
    /**
     * Save an object.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     *
     * @throws RuntimeException
     *
     * @return void
     */
    public function deleteDirectory(string $collection, string $objectID, string $property): void
    {
        $path = $this->buildPath($collection, $objectID, $property);

        try {
            $this->filesystem->deleteDirectory($path);
        } catch (\Exception $exception) {
            throw new RuntimeException('Unable to delete directory');
        }
    }

    /**
     * Save file to an object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @throws RuntimeException
     *
     * @return array
     */
    public function saveFile(string $collection, string $objectID, string $property, string $filePath): array
    {
        $filename = basename($filePath);
        $newpath  = $this->buildPath($collection, $objectID, $property, $filename);

        // File already exists, rename it
        if ($this->filesystem->fileExists($newpath)) {
            $newname  = self::getUniqueFilename($filename);
            $newpath  = $this->buildPath($collection, $objectID, $property, $newname);
        }

        if (!$this->filesystem->import($filePath, $newpath)) {
            throw new RuntimeException('File not saved');
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
     * @throws RuntimeException
     *
     * @return array
     */
    public function saveImage(string $collection, string $objectID, string $property, string $filePath): array
    {
        $filename = basename($filePath);
        $newpath  = $this->buildPath($collection, $objectID, $property, $filename);

        if (!$this->filesystem->import($filePath, $newpath)) {
            throw new RuntimeException('File not saved');
        }

        // Update to be Image data
        return [
            'name'       => basename($newpath),
            'size'       => intval($this->filesystem->fileSize($newpath)),
            'mime'       => $this->filesystem->mimeType($newpath),
            'uploadDate' => date('c'),
        ];
    }

    private static function getUniqueFilename(string $filename): string
    {
        $parts = pathinfo($filename);
        $ext   = isset($parts['extension']) ? '.' . $parts['extension'] : '';

        return sprintf('%s-%s%s', $parts['filename'], uniqid(), $ext);
    }

    private function buildPath(string $collection, string $objectID, string $property, ?string $filename = null): string
    {
        if (isset($filename)) {
            return sprintf(
                '%s/%s/%s/%s',
                $this->cleanString($collection),
                $this->cleanString($objectID),
                $this->cleanString($property),
                $filename
            );
        }

        return sprintf(
            '%s/%s/%s',
            $this->cleanString($collection),
            $this->cleanString($objectID),
            $this->cleanString($property),
        );
    }
}

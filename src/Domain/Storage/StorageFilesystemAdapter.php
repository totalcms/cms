<?php

namespace TotalCMS\Domain\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Filesystem.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
final class StorageFilesystemAdapter implements StorageAdapterInterface
{
    private FilesystemOperator $filesystem;

    /**
     * The constructor.
     *
     * @param FilesystemOperator $filesystem The filesystem handler
     */
    public function __construct(FilesystemOperator $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Access the flysystem filesystem.
     *
     * @return FilesystemOperator
     */
    public function flysystem(): FilesystemOperator
    {
        return $this->filesystem;
    }

    /**
     * Read file.
     *
     * @param string $location The path of file to read
     *
     * @return string
     */
    public function read(string $location): string
    {
        return $this->filesystem->read($location);
    }

    /**
     * Read file as stream.
     *
     * @param string $location The path of file to read
     *
     * @return resource
     */
    public function readStream(string $location)
    {
        return $this->filesystem->readStream($location);
    }

    /**
     * Delete file.
     *
     * @param string $location The path of file to delete
     *
     * @return bool
     */
    public function delete(string $location): bool
    {
        $this->filesystem->delete($location);

        return !$this->fileExists($location);
    }

    /**
     * Delete directory.
     *
     * @param string $location The path of directory to delete
     *
     * @return bool
     */
    public function deleteDirectory(string $location): bool
    {
        $this->filesystem->deleteDirectory($location);

        return !$this->fileExists($location);
    }

    /**
     * File exists.
     *
     * @param string $location The path of file
     *
     * @return bool True if exists
     */
    public function fileExists(string $location): bool
    {
        return $this->filesystem->fileExists($location);
    }

    /**
     * File mime type.
     *
     * @param string $location The path of file
     *
     * @return string the mime type
     */
    public function mimeType(string $location): string
    {
        return $this->filesystem->mimeType($location);
    }

    /**
     * File size.
     *
     * @param string $location The path of file
     *
     * @return int file size
     */
    public function fileSize(string $location): int
    {
        return $this->filesystem->fileSize($location);
    }

    /**
     * Write file contents.
     *
     * @param string $location The path of file to write to
     * @param string $contents The data to write to the file
     *
     * @return bool
     */
    public function write(string $location, string $contents): bool
    {
        $this->filesystem->write($location, $contents);

        return $this->filesystem->fileExists($location);
    }

    /**
     * Import a file into the filesystem.
     *
     * @param string $import Path to the file to import
     * @param string $dest Path to put the file
     *
     * @return bool
     */
    public function import(string $import, string $dest): bool
    {
        $stream = fopen($import, 'r+');
        $this->filesystem->writeStream($dest, $stream);

        return $this->filesystem->fileExists($dest);
    }

    /**
     * Move a file.
     *
     * @param string $old Existing path
     * @param string $new New location
     *
     * @return bool
     */
    public function move(string $old, string $new): bool
    {
        $this->filesystem->move($old, $new);

        return $this->filesystem->fileExists($new);
    }

    /**
     * List directories.
     *
     * @param string $path The path to iterate through
     *
     * @return array<string>
     */
    public function listDirectories(string $path): array
    {
        return $this->filesystem->listContents($path)
            ->filter(fn (StorageAttributes $attributes) => !$attributes->isFile())
            ->map(fn (StorageAttributes $attributes) => $attributes->path())
            ->toArray();
    }

    /**
     * List files.
     *
     * @param string $path The path to iterate through
     *
     * @return array<string>
     */
    public function listFiles(string $path): array
    {
        return $this->filesystem->listContents($path)
            ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
            ->filter(fn (StorageAttributes $attributes) => !str_starts_with(basename($attributes->path()), '.'))
            ->map(fn (StorageAttributes $attributes) => $attributes->path())
            ->toArray();
    }
}

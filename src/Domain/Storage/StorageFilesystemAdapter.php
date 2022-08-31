<?php

namespace App\Domain\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Filesystem.
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
     * Delete file.
     *
     * @param string $location The path of file to read
     *
     * @return bool
     */
    public function delete(string $location): bool
    {
        $this->filesystem->delete($location);

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
     * Write file contents.
     *
     * @param string $location The path of file to write to
     * @param string $contents The data to write to the file
     *
     * @return void
     */
    public function write(string $location, string $contents): void
    {
        $this->filesystem->write($location, $contents);
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

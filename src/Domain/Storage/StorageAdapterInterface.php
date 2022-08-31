<?php

namespace App\Domain\Storage;

interface StorageAdapterInterface
{
    /**
     * Read file.
     *
     * @param string $location The path of file to read
     *
     * @return string The content
     */
    public function read(string $location): string;

    /**
     * File exists.
     *
     * @param string $location The location of the file
     *
     * @return bool True if exists
     */
    public function fileExists(string $location): bool;

    /**
     * Save file.
     *
     * @param string $location The path of file to write to
     * @param string $contents The data to write to the file
     *
     * @return void
     */
    public function write(string $location, string $contents): void;

    /**
     * Delete file.
     *
     * @param string $location The path of file to write to
     *
     * @return bool
     */
    public function delete(string $location): bool;

    /**
     * List directories.
     *
     * @param string $directory The path to iterate through
     *
     * @return array<string>
     */
    public function listDirectories(string $directory): array;
}

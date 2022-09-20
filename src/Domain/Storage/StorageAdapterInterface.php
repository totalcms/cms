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
     * File mime type.
     *
     * @param string $location The path of file
     *
     * @return string the mime type
     */
    public function mimeType(string $location): string;

    /**
     * File size.
     *
     * @param string $location The path of file
     *
     * @return int file size
     */
    public function fileSize(string $location): int;

    /**
     * Save file.
     *
     * @param string $location The path of file to write to
     * @param string $contents The data to write to the file
     *
     * @return bool
     */
    public function write(string $location, string $contents): bool;

    /**
     * Import a file into the filesystem.
     *
     * @param string $import path to the file to import
     * @param string $dest path to put the file
     *
     * @return bool
     */
    public function import(string $import, string $dest): bool;

    /**
     * Move a file.
     *
     * @param string $old existing path
     * @param string $new new location
     *
     * @return bool
     */
    public function move(string $old, string $new): bool;

    /**
     * Delete file.
     *
     * @param string $location The path of file to write to
     *
     * @return bool
     */
    public function delete(string $location): bool;

    /**
     * Delete directory.
     *
     * @param string $location The path of directory to delete
     *
     * @return bool
     */
    public function deleteDirectory(string $location): bool;

    /**
     * List directories.
     *
     * @param string $directory The path to iterate through
     *
     * @return array<string>
     */
    public function listDirectories(string $directory): array;

    /**
     * List files.
     *
     * @param string $directory The path to iterate through
     *
     * @return array<string>
     */
    public function listFiles(string $directory): array;
}

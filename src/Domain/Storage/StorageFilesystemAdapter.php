<?php

namespace TotalCMS\Domain\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Filesystem.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
readonly class StorageFilesystemAdapter implements StorageAdapterInterface
{
	/**
	 * The constructor.
	 *
	 * @param FilesystemOperator $filesystem The filesystem handler
	 */
	public function __construct(private FilesystemOperator $filesystem)
	{
	}

	/**
	 * Access the flysystem filesystem.
	 */
	public function flysystem(): FilesystemOperator
	{
		return $this->filesystem;
	}

	/**
	 * Read file.
	 *
	 * @param string $location The path of file to read
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

	public function directoryExists(string $location): bool
	{
		return $this->filesystem->directoryExists($location);
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
	 */
	public function write(string $location, string $contents): bool
	{
		$this->filesystem->write($location, $contents);

		return $this->filesystem->fileExists($location);
	}

	/**
	 * Import a file into the filesystem.
	 *
	 * Read-only fopen ('r') is correct here: import only consumes the source
	 * stream. Using 'r+' was a long-standing bug — it required write
	 * permission on the source, which silently broke CSV image imports
	 * whenever the referenced files weren't owned by the web server user
	 * (almost always, in practice). With 'r+' fopen returned false and
	 * writeStream got a non-resource, leading to confusing errors logged
	 * to importer.log without any clear cause.
	 *
	 * @param string $import Path to the file to import
	 * @param string $dest Path to put the file
	 */
	public function import(string $import, string $dest): bool
	{
		$stream = @fopen($import, 'r');
		if ($stream === false) {
			throw new \RuntimeException("Unable to read source file for import: {$import}");
		}

		try {
			$this->filesystem->writeStream($dest, $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}

		return $this->filesystem->fileExists($dest);
	}

	/**
	 * Move a file.
	 *
	 * @param string $old Existing path
	 * @param string $new New location
	 */
	public function move(string $old, string $new): bool
	{
		$this->filesystem->move($old, $new);

		return $this->filesystem->fileExists($new);
	}

	public function copyDirectory(string $sourceDir, string $targetDir): bool
	{
		$contents = $this->filesystem->listContents($sourceDir, true); // recursive = true

		foreach ($contents as $item) {
			$relativePath = ltrim(str_replace($sourceDir, '', $item->path()), '/');
			$targetPath   = rtrim($targetDir, '/') . '/' . $relativePath;

			if ($item->isDir()) {
				$this->filesystem->createDirectory($targetPath);
			} elseif ($item->isFile()) {
				$stream = $this->filesystem->readStream($item->path());
				$this->filesystem->writeStream($targetPath, $stream);
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		}

		return $this->filesystem->directoryExists($targetDir);
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
			->filter(fn (StorageAttributes $attributes): bool => !$attributes->isFile())
			->map(fn (StorageAttributes $attributes): string => $attributes->path())
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
			->filter(fn (StorageAttributes $attributes): bool => $attributes->isFile())
			->filter(fn (StorageAttributes $attributes): bool => !str_starts_with(basename($attributes->path()), '.'))
			->map(fn (StorageAttributes $attributes): string => $attributes->path())
			->toArray();
	}
}

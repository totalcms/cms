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
	 * @param string $rootPath The filesystem's root on disk. Lets a write keep
	 *                         an existing file's mode; without it the replaced
	 *                         file gets the default mode.
	 */
	public function __construct(private FilesystemOperator $filesystem, private string $rootPath = '')
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
		$this->replace($location, fn (string $tmp) => $this->filesystem->write($tmp, $contents));

		return $this->filesystem->fileExists($location);
	}

	/**
	 * Write a stream to a file, replacing it the same way write() does.
	 *
	 * @param string $location The path of file to write to
	 * @param resource $stream The data to write to the file
	 */
	public function writeStream(string $location, $stream): bool
	{
		$this->replace($location, fn (string $tmp) => $this->filesystem->writeStream($tmp, $stream));

		return $this->filesystem->fileExists($location);
	}

	/**
	 * Write to a temp file beside the target, then rename it into place. The
	 * local adapter's move() is a rename(), which is atomic on POSIX, so a
	 * reader sees the old file or the new one, never a half-written one.
	 * The temp name starts with a dot so a collection listing never takes it
	 * for an object.
	 *
	 * @param callable(string): void $writeTo writes the new contents to the temp path
	 */
	private function replace(string $location, callable $writeTo): void
	{
		$dir = dirname($location);
		$tmp = ($dir === '.' ? '' : $dir . '/') . '.' . basename($location) . '.tmp.' . bin2hex(random_bytes(4));

		try {
			$writeTo($tmp);
			$this->keepMode($location, $tmp);
			$this->filesystem->move($tmp, $location);
		} catch (\Throwable $e) {
			if ($this->filesystem->fileExists($tmp)) {
				$this->filesystem->delete($tmp);
			}

			throw $e;
		}
	}

	/**
	 * Give the temp file the mode of the file it replaces, so a file someone
	 * made private (or group-writable) stays that way.
	 */
	private function keepMode(string $location, string $tmp): void
	{
		if ($this->rootPath === '') {
			return;
		}
		$target = $this->rootPath . '/' . ltrim($location, '/');
		if (!is_file($target)) {
			return;
		}
		$mode = @fileperms($target);
		if ($mode !== false) {
			@chmod($this->rootPath . '/' . ltrim($tmp, '/'), $mode & 0777);
		}
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

	public function listFileTimes(string $path): array
	{
		$times = [];
		foreach ($this->filesystem->listContents($path) as $attributes) {
			if ($attributes->isFile() && !str_starts_with(basename($attributes->path()), '.')) {
				$times[$attributes->path()] = (int)$attributes->lastModified();
			}
		}

		return $times;
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

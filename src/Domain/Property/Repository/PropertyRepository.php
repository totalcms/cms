<?php

namespace TotalCMS\Domain\Property\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Repository.
 */
class PropertyRepository extends StorageRepository
{
	public function deleteDirectory(string $collection, string $objectID, string $property, ?string $name = null, ?string $subpath = null): void
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, $name, $subpath);

		try {
			$this->filesystem->deleteDirectory($path);
		} catch (\Exception) {
			throw new \RuntimeException('Unable to delete directory');
		}
	}

	public function deletePropertyCache(string $collection, string $objectID, string $property): bool
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, '.cache');

		try {
			$this->filesystem->deleteDirectory($path);
		} catch (\Exception) {
			throw new \RuntimeException('Unable to delete cache directory');
		}

		return !$this->filesystem->fileExists($path);
	}

	public function deleteFileCache(string $collection, string $objectID, string $property, string $filename): bool
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, ".cache/$filename");

		try {
			$this->filesystem->deleteDirectory($path);
		} catch (\Exception) {
			throw new \RuntimeException('Unable to delete cache directory');
		}

		return !$this->filesystem->fileExists($path);
	}

	public function deleteFile(string $collection, string $objectID, string $property, string $filename, ?string $subpath = null): void
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);

		try {
			$this->filesystem->delete($path);
		} catch (\Exception) {
			throw new \RuntimeException("Unable to delete file $filename");
		}
		$this->deleteFileCache($collection, $objectID, $property, $filename);
	}

	/** @return array<string,string|int> */
	public function saveFile(string $collection, string $objectID, string $property, string $filePath, ?string $subpath = null): array
	{
		$filename = basename($filePath);
		$newpath  = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);

		// File already exists, rename it
		if ($this->filesystem->fileExists($newpath)) {
			$newname  = $this->getUniqueFilename($filename);
			$newpath  = PathUtils::buildPath($collection, $objectID, $property, $newname, $subpath);
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

	public function renameFolder(string $collection, string $objectID, string $property, string $oldPath, string $newName): bool
	{
		$parts      = explode('/', trim($oldPath, '/'));
		$parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : null;

		$oldDir = PathUtils::buildPath($collection, $objectID, $property, end($parts), $parentPath);
		$newDir = PathUtils::buildPath($collection, $objectID, $property, $newName, $parentPath);

		return $this->filesystem->move($oldDir, $newDir);
	}

	public function moveFile(string $collection, string $objectID, string $property, string $filename, ?string $subpath = null, ?string $newpath = null): bool
	{
		$path    = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);
		$newpath = PathUtils::buildPath($collection, $objectID, $property, $filename, $newpath);

		if ($this->filesystem->fileExists($newpath)) {
			// $newname  = self::getUniqueFilename($filename);
			// $newpath  = PathUtils::buildPath($collection, $objectID, $property, $newname, $subpath);
			throw new \RuntimeException('File already exists in destination');
		}

		return $this->filesystem->move($path, $newpath);
	}

	public function fileExists(string $collection, string $objectID, string $property, string $filename, ?string $subpath = null): bool
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);

		return $this->filesystem->fileExists($path);
	}

	/** @return resource */
	public function streamFile(string $collection, string $objectID, string $property, string $filename, ?string $subpath = null)
	{
		$path  = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);

		return $this->filesystem->readStream($path);
	}

	/** @return array<string,string|int> */
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
			throw new \RuntimeException('Image not saved');
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

	private function getUniqueFilename(string $filename): string
	{
		$parts = pathinfo($filename);
		$ext   = isset($parts['extension']) ? '.' . $parts['extension'] : '';

		// stripping last 5 characters from uniqid to make it shorter
		// still unique enough for our purposes (1 in a million)
		$unique = substr(uniqid(), -5);

		return sprintf('%s-%s%s', $parts['filename'], $unique, $ext);
	}

	public function mimeType(string $collection, string $objectID, string $property, string $filename, ?string $subpath = null): string
	{
		$path = PathUtils::buildPath($collection, $objectID, $property, $filename, $subpath);

		return $this->filesystem->mimeType($path);
	}
}

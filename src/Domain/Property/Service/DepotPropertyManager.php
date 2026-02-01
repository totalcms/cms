<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

class DepotPropertyManager
{
	public function __construct(public DepotData &$depot)
	{
	}

	public function &addFile(FileData $newfile, ?string $subpath = null): DepotData
	{
		// Directly find or create the folder in the specified path and add the file
		$folder   = &self::findOrCreateFolderByPath($this->depot->files, $subpath);
		$folder[] = $newfile;

		return $this->depot;
	}

	public function &createFolder(string $path): DepotData
	{
		// Create the folder in the specified path
		self::findOrCreateFolderByPath($this->depot->files, $path);

		return $this->depot;
	}

	public function &moveFile(string $name, string $subpath, string $destination): DepotData
	{
		$fileToMove   = $this->findFileByName($this->depot->files, $name, $subpath);
		$destFolder   = &self::findOrCreateFolderByPath($this->depot->files, $destination);
		$destFolder[] = $fileToMove;

		$this->deleteFile($name, $subpath);

		return $this->depot;
	}

	public function fetchFile(string $name, ?string $subpath = null): ?FileData
	{
		return $this->findFileByName($this->depot->files, $name, $subpath);
	}

	public function fileExists(string $name, ?string $subpath = null): bool
	{
		$file = $this->fetchFile($name, $subpath);

		return $file instanceof FileData;
	}

	public function &deleteFile(string $name, ?string $subpath = null): DepotData
	{
		// Directly find or create the folder in the specified path and add the file
		$folder = &self::findOrCreateFolderByPath($this->depot->files, $subpath);
		foreach ($folder as $index => &$item) {
			if ($item->name === $name) {
				unset($folder[$index]);
				break;
			}
		}

		return $this->depot;
	}

	public function &renameFolder(string $oldPath, string $newName): DepotData
	{
		// Split path to find the parent path and the current folder name
		$parts      = explode('/', trim($oldPath, '/'));
		$parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : null;

		$parent = &self::findOrCreateFolderByPath($this->depot->files, $parentPath);
		foreach ($parent as &$item) {
			if ($item instanceof FolderData && $item->name === end($parts)) {
				$item->name = $newName;
				break;
			}
		}

		return $this->depot;
	}

	/** @param array<string,mixed> $newMeta */
	public function &patchMeta(string $name, array $newMeta, ?string $subpath = null): DepotData
	{
		// Directly find or create the folder in the specified path and add the file
		$folder = &self::findOrCreateFolderByPath($this->depot->files, $subpath);
		foreach ($folder as $index => &$item) {
			if ($item->name === $name) {
				$merged = array_merge($item->transform(), $newMeta);
				$folder[$index] = new FileData($merged, $item->settings);
				break;
			}
		}

		return $this->depot;
	}

	/**
	 * @param array<FolderData|FileData> $files
	 *
	 * @return array<FolderData|FileData>
	 */
	private static function &findOrCreateFolderByPath(array &$files, ?string $path): array
	{
		// If path is empty, return the top-level structure
		if ($path === null || $path === '') {
			return $files;
		}

		// Break down the path into an array of folder names
		$pathParts         = explode('/', trim($path, '/'));
		$currentFolderName = array_shift($pathParts);

		// Traverse through the structure to find or create the specified path
		foreach ($files as &$item) {
			if (!($item instanceof FolderData)) {
				continue;
			}
			if ($item->name === $currentFolderName) {
				// If this is the final part of the path, return this folder
				if ($pathParts === []) {
					return $item->files;
				}

				// Recurse into the existing folder
				return self::findOrCreateFolderByPath($item->files, implode('/', $pathParts));
			}
		}

		// Folder not found, so create it
		$newFolder = new FolderData($currentFolderName);
		$files[]   = $newFolder;

		// Process the new folder that we just added
		$lastIndex = count($files) - 1;
		$lastItem  = $files[$lastIndex];
		if (!$lastItem instanceof FolderData) {
			throw new \RuntimeException('Expected instance of FolderData');
		}

		return self::findOrCreateFolderByPath($lastItem->files, implode('/', $pathParts));
	}

	/** @param array<FolderData|FileData> $files */
	private function findFileByName(array $files, string $filename, ?string $subpath = null): ?FileData
	{
		// Locate the folder specified by $subpath if provided
		if ($subpath) {
			$folder = self::findOrCreateFolderByPath($files, $subpath);

			return self::findFileByNameInFolder($folder, $filename);
		}

		// If no $subpath is provided, search at the top level
		return self::findFileByNameInFolder($files, $filename);
	}

	/** @param array<FolderData|FileData> $files */
	private static function findFileByNameInFolder(array $files, string $filename): ?FileData
	{
		foreach ($files as $item) {
			if ($item instanceof FileData && $item->name === $filename) {
				// File found, return it
				return $item;
			}

			if ($item instanceof FolderData) {
				// Recursively search within the folder
				$found = self::findFileByNameInFolder($item->files, $filename);
				if ($found instanceof FileData) {
					return $found;
				}
			}
		}

		// File not found
		return null;
	}
}

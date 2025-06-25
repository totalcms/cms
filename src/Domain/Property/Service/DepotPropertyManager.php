<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

final class DepotPropertyManager
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
		$fileToMove   = self::findFileByName($this->depot->files, $name, $subpath);
		$destFolder   = &self::findOrCreateFolderByPath($this->depot->files, $destination);
		$destFolder[] = $fileToMove;

		$this->deleteFile($name, $subpath);

		return $this->depot;
	}

	public function fetchFile(string $name, ?string $subpath = null): ?FileData
	{
		return self::findFileByName($this->depot->files, $name, $subpath);
	}

	public function fileExists(string $name, ?string $subpath = null): bool
	{
		$file = $this->fetchFile($name, $subpath);

		return $file !== null;
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

	/** @param array<string,mixed> $newMeta */
	public function &patchMeta(string $name, array $newMeta, ?string $subpath = null): DepotData
	{
		// Directly find or create the folder in the specified path and add the file
		$folder = &self::findOrCreateFolderByPath($this->depot->files, $subpath);
		foreach ($folder as &$item) {
			if ($item->name === $name) {
				foreach ($newMeta as $key => $value) {
					if (property_exists($item, $key)) {
						$item->$key = $value;
					}
				}
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
		if (empty($path)) {
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
				if (empty($pathParts)) {
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
	private static function findFileByName(array $files, string $filename, ?string $subpath = null): ?FileData
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
				if ($found !== null) {
					return $found;
				}
			}
		}

		// File not found
		return null;
	}
}

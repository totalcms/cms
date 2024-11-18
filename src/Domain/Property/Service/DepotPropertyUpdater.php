<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

final class DepotPropertyUpdater
{
	public function __construct(public DepotData &$depot)
	{
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}
	}

	public function &addFile(FileData $newfile, ?string $subpath = null): DepotData
	{
		// Directly find or create the folder in the specified path and add the file
		$folder   = &self::findOrCreateFolderByPath($this->depot->files, $subpath);
		$folder[] = $newfile;

		return $this->depot;
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
}

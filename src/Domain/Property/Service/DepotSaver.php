<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Component\Depot;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

final class DepotSaver extends FileSaver
{
	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null
	): ObjectData
	{
		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		$fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);
		$newfile  = new FileData($fileinfo);
		$depot    = $this->fetchProperty($collection, $objectID, $property);

		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$depot = self::appendFileToDepot($depot, $newfile, $subpath);

		return $this->updateObject($collection, $objectID, $property, $depot->transform());
	}

	private static function appendFileToDepot(DepotData $depot, FileData $newfile, ?string $path) : DepotData
	{
		$files        = $depot->files;
		$folder       = self::findOrCreateFolderByPath($files, $path);
		$folder[]     = $newfile;
		$depot->files = $files;
		return $depot;
	}

	/**
	 * @param array<FolderData|FileData> $files
	 *
	 * @return array<FolderData|FileData>
	 */
	private static function &findOrCreateFolderByPath(array &$files, ?string $path) : array
	{
		 // If path is empty, return the top-level structure
		 if (empty($path)) {
			return $files;
		}

		// Break down the path into an array of folder names
		$pathParts = explode('/', trim($path, '/'));
		$currentFolderName = array_shift($pathParts);

		echo("Current folder name: " . $currentFolderName);

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
		$files[] = $newFolder;
		echo("Created new folder: " . $currentFolderName);

		$lastItem = $files[count($files) - 1];
		if (!$lastItem instanceof FolderData) {
			throw new \RuntimeException('Expected instance of FolderData');
		}
		return self::findOrCreateFolderByPath($lastItem->files, implode('/', $pathParts));
	}
}

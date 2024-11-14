<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
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

		$files    = $this->fetchProperty($collection, $objectID, $property)->transform();
		$fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);

		$folder   = self::findOrCreateFolderByPath($files, $subpath);
		$folder[] = (new FileData($fileinfo))->transform();

		return $this->updateObject($collection, $objectID, $property, $files);
	}

	public function newFolder(
		string $collection,
		string $objectID,
		string $property,
		string $folderName,
		?string $subpath = null
	): ObjectData
	{
		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		$files = $this->fetchProperty($collection, $objectID, $property)->transform();

		$folder   = self::findOrCreateFolderByPath($files, $subpath);
		$folder[] = (new FolderData($folderName))->transform();

		return $this->updateObject($collection, $objectID, $property, $files);
	}

	/**
	 * @param array<mixed> $structure
	 *
	 * @return array<mixed>
	 */
	private static function findOrCreateFolderByPath(array &$structure, ?string $path) : array
	{
		 // If path is empty, return the top-level structure
		 if (empty($path)) {
			return $structure;
		}

		// Break down the path into an array of folder names
		$pathParts = explode('/', trim($path, '/'));
		$currentFolderName = array_shift($pathParts);

		// Traverse through the structure to find or create the specified path
		foreach ($structure as &$item) {
			if (isset($item['mime']) && $item['mime'] === 'folder' && $item['name'] === $currentFolderName) {
				// If this is the final part of the path, return this folder
				if (empty($pathParts)) {
					return $item['files'];
				}
				// Recurse into the existing folder
				return self::findOrCreateFolderByPath($item['files'], implode('/', $pathParts));
			}
		}

		// Folder not found, so create it
		$newFolder = (new FolderData($currentFolderName))->transform();
		$structure[] = $newFolder;

		// Recursively create the rest of the path in the new folder
		return self::findOrCreateFolderByPath($structure[count($structure) - 1]['files'], implode('/', $pathParts));
	}
}

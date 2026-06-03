<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\PropertyData;

class DepotSaver extends FileSaver
{
	public string $type = 'depot';

	public function rebuildFromStorage(string $collection, string $id, string $property, ?string $subpath = null): ?PropertyData
	{
		// Walk the on-disk folder tree (listPropertyFiles is non-recursive and
		// hides folders) and re-insert every file via DepotPropertyManager, which
		// recreates the folder hierarchy exactly as an upload would. Empty folders
		// and a previously-set depot password/protected flag cannot be recovered.
		$entries = $this->scanDepotTree($collection, $id, $property, '');
		if ($entries === []) {
			return null;
		}

		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);
		foreach ($entries as $entry) {
			$childPath            = $entry['subpath'] !== '' ? $entry['subpath'] : null;
			$fileData             = $this->describeStoredFile($collection, $id, $property, $entry['name'], $childPath);
			$fileData['download'] = $entry['name'];
			$manager->addFile(new FileData($fileData), $childPath);
		}

		return $depot;
	}

	/**
	 * Depth-first collect every file under the property, with the folder subpath
	 * it lives in (relative to the property root, '' = top level).
	 *
	 * @return list<array{name:string,subpath:string}>
	 */
	private function scanDepotTree(string $collection, string $id, string $property, string $subpath): array
	{
		$resolved = $subpath !== '' ? $subpath : null;
		$entries  = [];

		foreach ($this->storage->listPropertyFiles($collection, $id, $property, $resolved) as $file) {
			$entries[] = ['name' => (string)$file['name'], 'subpath' => $subpath];
		}

		foreach ($this->storage->listPropertyDirectories($collection, $id, $property, $resolved) as $dir) {
			$child   = $subpath === '' ? $dir : $subpath . '/' . $dir;
			$entries = array_merge($entries, $this->scanDepotTree($collection, $id, $property, $child));
		}

		return $entries;
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null,
	): ObjectData {
		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		$depot = $this->fetchProperty($collection, $objectID, $property);
		if (!$depot instanceof DepotData) {
			throw new \RuntimeException('Expected instance of DepotData');
		}

		$fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);

		// Keep the downlaod name as the original file name in case the file was renamed
		$fileinfo['download'] = basename($filePath);

		// Directly find or create the folder in the specified path and add the file
		$depotManager = new DepotPropertyManager($depot);
		$depotManager->addFile(new FileData($fileinfo), $subpath);

		return $this->updateObject($collection, $objectID, $property, $depot);
	}
}

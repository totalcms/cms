<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\GallerySaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Collection Object Importer
 *
 * Importing objects from external sources
 * It's main difference from ObjectSaver is that it will
 * import images, galleries, files and depots from local filesystem
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class ObjectImporter
{
	/** @var array<string,string> $images */
	private array $images = [];
	/** @var array<string,string> $galleries */
	private array $galleries = [];
	/** @var array<string,string> $files */
	private array $files = [];
	/** @var array<string,string> $depots */
	private array $depots = [];

	private string $collection;
	private string $objectID;

	public function __construct(
		private CollectionSchemaFetcher $schemaFetcher,
		private ObjectSaver $objectSaver,
		private ObjectPatcher $objectPatcher,
		private ObjectFetcher $objectFetcher,
		private ImageSaver $imageSaver,
		private GallerySaver $gallerySaver,
		private FileSaver $fileSaver,
		private DepotSaver $depotSaver,
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function importObject(string $collection, array $objectData): ObjectData
	{
		$this->collection = $collection;

		$objectData = $this->saveRefPropsforLaterProcessing($objectData);
		$object     = $this->objectSaver->saveObject($collection, $objectData);

		$this->objectID = $object->id;
		$this->saveImages();
		$this->saveFiles();
		$this->saveGalleries();
		$this->saveDepots();

		return $this->objectFetcher->fetchObject($collection, $this->objectID);
	}

	/** @param array<string,mixed> $objectData */
	public function updateObject(string $collection, array $objectData): ObjectData
	{
		$this->collection = $collection;

		$objectData = $this->saveRefPropsforLaterProcessing($objectData);

		$this->objectID = $objectData['id'] ?? null;
		if ($this->objectID === null) {
			throw new \InvalidArgumentException('Object ID is required for updating');
		}
		if (!$this->objectFetcher->existsObject($collection, $this->objectID)) {
			throw new \InvalidArgumentException('Object does not exist');
		}

		$this->objectPatcher->patchObject($collection, $this->objectID, $objectData);

		$this->saveImages();
		$this->saveFiles();
		$this->saveGalleries();
		$this->saveDepots();

		return $this->objectFetcher->fetchObject($collection, $this->objectID);
	}

	/**
	 * Extract images, galleries, files and depots from object data
	 * These will get processed separately and files looked for in the local filesystem
	 *
	 * @param array<string,mixed> $objectData
	 * @return array<string,mixed>
	 */
	private function saveRefPropsforLaterProcessing(array $objectData) : array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($this->collection);

		// Filter out properties that are not in the schema
		$objectData = array_filter(
			$objectData,
			fn($value, $name) => isset($schema->properties[$name]),
			ARRAY_FILTER_USE_BOTH
		);

		foreach ($schema->properties as $name => $property) {
			// Skip properties that are not references or if the data is not set
			if (!isset($property['$ref'], $objectData[$name])) {
				continue;
			}

			switch ($property['$ref']) {
				case SchemaData::PROPERTY_TYPE_TO_REF['image']:
					$this->images[$name] = $objectData[$name];
					$objectData[$name]   = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['gallery']:
					$this->galleries[$name] = $objectData[$name];
					$objectData[$name]      = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['file']:
					$this->files[$name] = $objectData[$name];
					$objectData[$name]  = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['depot']:
					$this->depots[$name] = $objectData[$name];
					$objectData[$name]   = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['list']:
					$objectData[$name] = self::convertList($objectData[$name]);
					break;
			}
		}
		return $objectData;
	}

	private function saveImages(): void
	{
		foreach ($this->images as $property => $path) {
			if (file_exists($path)) {
				$this->imageSaver->save($this->collection, $this->objectID, $property, $path);
			}
		}
	}

	private function saveFiles(): void
	{
		foreach ($this->files as $property => $path) {
			if (file_exists($path)) {
				$this->fileSaver->save($this->collection, $this->objectID, $property, $path);
			}
		}
	}

	/** @SuppressWarnings("PHPMD.ErrorControlOperator") */
	private function saveGalleries(): void
	{
		foreach ($this->galleries as $property => $path) {
			if (!file_exists($path) || !is_dir($path)) {
				continue;
			}
			// Loop through the directory and save each image
			$iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
			foreach ($iterator as $fileInfo) {
				if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) {
					// Skip thumbnails from Total CMS 1.x
					if (preg_match('/(-th|-sq)\.[^\/]+$/', $fileInfo->getBasename())) {
						continue;
					}
					// Skip non-images
					if (!@is_array(getimagesize($fileInfo->getPathname()))) {
						continue;
					}
					$this->gallerySaver->save($this->collection, $this->objectID, $property, $fileInfo->getPathname());
				}
			}
		}
	}

	private function saveDepots(): void
	{
		foreach ($this->depots as $property => $path) {
			if (!file_exists($path) || !is_dir($path)) {
				continue;
			}
			// Loop through the directory and save each file
			// This implementation assumes that the files are in the root of the depot
			// and not in subdirectories. This can be udpated if needed.
			$iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
			foreach ($iterator as $fileInfo) {
				if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) {
					$this->depotSaver->save($this->collection, $this->objectID, $property, $fileInfo->getPathname());
				}
			}
		}
	}

	/** @return array<string> */
	private static function convertList(string $list): array
	{
		$list = explode(',', $list);
		$list = array_map('trim', $list);
		return array_filter($list);
	}
}

<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\GallerySaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Collection Object Importer.
 *
 * Importing objects from external sources
 * It's main difference from ObjectSaver is that it will
 * import images, galleries, files and depots from local filesystem
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ObjectImporter
{
	/** @var array<string,string> */
	private array $images = [];
	/** @var array<string,string> */
	private array $galleries = [];
	/** @var array<string,string> */
	private array $files = [];
	/** @var array<string,string> */
	private array $depots = [];

	private string $collection;
	private string $objectID;

	public function __construct(
		private readonly SchemaFetcher $schemaFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly ObjectPatcher $objectPatcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ImageSaver $imageSaver,
		private readonly GallerySaver $gallerySaver,
		private readonly FileSaver $fileSaver,
		private readonly DepotSaver $depotSaver,
	) {
	}

	/** @param array<string,mixed> $objectData */
	public function importObject(string $collection, array $objectData): ObjectData
	{
		$this->collection = $collection;

		// Reset property arrays for each import
		$this->images    = [];
		$this->galleries = [];
		$this->files     = [];
		$this->depots    = [];

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

		// Reset property arrays for each update
		$this->images    = [];
		$this->galleries = [];
		$this->files     = [];
		$this->depots    = [];

		$objectData = $this->saveRefPropsforLaterProcessing($objectData);

		if (empty($objectData['id'])) {
			throw new \InvalidArgumentException('Object ID is required for updating');
		}
		if (!$this->objectFetcher->existsObject($collection, $objectData['id'])) {
			throw new \InvalidArgumentException('Object does not exist');
		}

		$this->objectID = $objectData['id'];

		$this->objectPatcher->patchObject($collection, $this->objectID, $objectData);

		$this->saveImages();
		$this->saveFiles();
		$this->saveGalleries();
		$this->saveDepots();

		return $this->objectFetcher->fetchObject($collection, $this->objectID);
	}

	/**
	 * Extract images, galleries, files and depots from object data
	 * These will get processed separately and files looked for in the local filesystem.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array<string,mixed>
	 */
	private function saveRefPropsforLaterProcessing(array $objectData): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($this->collection);

		// Filter out properties that are not in the schema
		$objectData = array_filter(
			$objectData,
			fn ($value, $name): bool => isset($schema->properties[$name]),
			ARRAY_FILTER_USE_BOTH
		);

		foreach ($schema->properties as $name => $property) {
			// Handle string properties that might have escaped newlines from CSV
			if (isset($property['type']) && $property['type'] === 'string' && isset($objectData[$name]) && is_string($objectData[$name])) {
				// Convert literal \n back to actual newlines for proper display
				$objectData[$name] = str_replace('\\n', "\n", $objectData[$name]);
				continue;
			}

			// Skip properties that are not references or if the data is not set
			if (!isset($property['$ref'], $objectData[$name]) || !is_string($objectData[$name])) {
				continue;
			}

			// Check if the property is a reference to an image, gallery, file or depot
			// and if the data is a valid JSON string and decode the JSON string and continue
			if (in_array($property['$ref'], [
				SchemaData::PROPERTY_TYPE_TO_REF['image'],
				SchemaData::PROPERTY_TYPE_TO_REF['gallery'],
				SchemaData::PROPERTY_TYPE_TO_REF['file'],
				SchemaData::PROPERTY_TYPE_TO_REF['depot'],
			], true) && $this->isJson($objectData[$name])) {
				$objectData[$name] = json_decode($objectData[$name], true);
				continue;
			}

			switch ($property['$ref']) {
				case SchemaData::PROPERTY_TYPE_TO_REF['image']:
					$this->images[$name] = $this->replacePathTemplates($objectData[$name]);
					$objectData[$name]   = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['gallery']:
					$this->galleries[$name] = $this->replacePathTemplates($objectData[$name]);
					$objectData[$name]      = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['file']:
					$this->files[$name] = $this->replacePathTemplates($objectData[$name]);
					$objectData[$name]  = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['depot']:
					$this->depots[$name] = $this->replacePathTemplates($objectData[$name]);
					$objectData[$name]   = [];
					break;
				case SchemaData::PROPERTY_TYPE_TO_REF['list']:
					$objectData[$name] = $this->convertList($objectData[$name]);
					break;
			}
		}

		return $objectData;
	}

	private function isJson(string $data): bool
	{
		// Check if the data is valid JSON
		json_decode($data);

		return json_last_error() === JSON_ERROR_NONE;
	}

	private function saveImages(): void
	{
		foreach ($this->images as $property => $path) {
			if (file_exists($path)) {
				$this->imageSaver->save($this->collection, $this->objectID, $property, $path);

				// Check for alt text file and update the image item if found
				$altContent = $this->getImageAltText($path);
				if ($altContent !== '' && $altContent !== false) {
					$this->objectPatcher->patchObjectProperty(
						$this->collection,
						$this->objectID,
						$property,
						['alt' => $altContent]
					);
				}
			}
		}
	}

	private function getImageAltText(string $imagePath): string|false
	{
		$dir      = dirname($imagePath);
		$filename = pathinfo($imagePath, PATHINFO_FILENAME);
		$altExts  = ['cms', 'txt']; // cms extension is used for Total CMS 1.x
		// If the image has an alternative text file, save it as well
		foreach ($altExts as $ext) {
			$altPath = $dir . '/' . $filename . '.' . $ext;
			if (file_exists($altPath)) {
				return file_get_contents($altPath);
			}
		}

		return false;
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
					$imagePath = $fileInfo->getPathname();
					$this->gallerySaver->save($this->collection, $this->objectID, $property, $imagePath);

					// Check for alt text file and update the gallery item if found
					$altContent = $this->getImageAltText($imagePath);
					if ($altContent !== '' && $altContent !== false) {
						// Get the filename to use as the identifier for the gallery item
						$filename = $fileInfo->getFilename();
						$this->objectPatcher->patchObjectPropertyMeta(
							$this->collection,
							$this->objectID,
							$property,
							$filename,
							['alt' => $altContent]
						);
					}
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

	/** @SuppressWarnings("PHPMD.Superglobals") */
	private function replacePathTemplates(string $path = ''): string
	{
		return str_replace('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'], $path);
	}

	/** @return array<string> */
	private function convertList(string $list): array
	{
		$list = explode(',', $list);
		$list = array_map('trim', $list);

		return array_filter($list);
	}
}

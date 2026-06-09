<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Traits\LoggerAwareTrait;

class FileSaver
{
	use LoggerAwareTrait;

	public string $type = 'file';

	/** @var array<string,mixed> */
	protected array $settings = [];

	public function __construct(
		protected PropertyRepository $storage,
		protected PropertyFetcher $propFetcher,
		protected ObjectSaver $objectSaver,
		protected ObjectPatcher $objectPatcher,
		protected ObjectFetcher $objectFetcher,
		protected LoggerFactory $loggerFactory,
		protected Config $config,
	) {
	}

	/** @param array<string,mixed> $settings */
	public function setSettings(array $settings): void
	{
		$this->settings = $settings;
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

		// Clean up existing files at this exact path (top-level or nested). Without
		// $subpath, this would wipe the whole property dir — including sibling
		// card children — which would be a data-loss bug.
		$this->storage->deleteDirectory($collection, $objectID, $property, null, $subpath);

		// Save new file at the same nested location.
		$fileInfo = $this->storage->saveFile($collection, $objectID, $property, $filePath, $subpath);

		$newData = $fileInfo;

		if ($objectExists) {
			// Merge with existing user-set fields if present
			$fileProperty = $this->fetchExistingChildProperty($collection, $objectID, $property, $subpath);
			$keep         = ['download', 'comments', 'tags', 'protected', 'password'];
			$existingData = array_filter($fileProperty->transform(), fn ($key): bool => in_array($key, $keep), ARRAY_FILTER_USE_KEY);
			if (!empty($existingData['download'])) {
				// Update the extension of the name if the new file has a different extension
				$newExt      = pathinfo((string)$fileInfo['name'], PATHINFO_EXTENSION);
				$existingExt = pathinfo((string)$existingData['download'], PATHINFO_EXTENSION);

				if ($newExt !== $existingExt) {
					$existingData['download'] = pathinfo((string)$existingData['download'], PATHINFO_FILENAME) . '.' . $newExt;
				}
			}
			$newData = array_merge($fileProperty->transform(), $fileInfo, $existingData);
		}

		$fileData = new FileData($newData);

		return $this->updateObject($collection, $objectID, $property, $fileData, $subpath);
	}

	/**
	 * Re-derive this property's data from files already on disk (recovery path).
	 * Unlike save(), it does NOT import/move an upload and does NOT write the
	 * object — the caller patches the returned PropertyData. Returns null when
	 * there are no files to rebuild from. A file field is single-file; subclasses
	 * override for image/gallery/depot.
	 */
	public function rebuildFromStorage(string $collection, string $id, string $property, ?string $subpath = null): ?PropertyData
	{
		$files = $this->storage->listPropertyFiles($collection, $id, $property, $subpath);
		if ($files === []) {
			return null;
		}

		return new FileData($this->describeStoredFile($collection, $id, $property, (string)$files[0]['name'], $subpath));
	}

	/**
	 * Build the base fileData array (name/size/mime/uploadDate) for a file that
	 * is ALREADY in storage — the equivalent of what saveFile() returns, without
	 * the move. uploadDate is "now" (the original is unknowable for an orphan).
	 *
	 * @return array<string,string|int>
	 */
	protected function describeStoredFile(string $collection, string $id, string $property, string $filename, ?string $subpath): array
	{
		return [
			'name'       => $filename,
			'size'       => $this->storage->fileSize($collection, $id, $property, $filename, $subpath),
			'mime'       => $this->storage->mimeType($collection, $id, $property, $filename, $subpath),
			'uploadDate' => date('c'),
		];
	}

	protected function createObject(string $collection, string $objectID, string $property): void
	{
		try {
			$this->objectSaver->saveObject($collection, [
				'id'      => $objectID,
				$property => $this->createPropertyObject($collection, $property)->transform(),
			]);
		} catch (\Exception $e) {
			$msg = "Object $objectID does not exist in collection $collection to save file ($property) to.";
			throw new \UnexpectedValueException($msg . $e->getMessage(), $e->getCode(), $e);
		}
	}

	protected function createPropertyObject(string $collection, string $property): PropertyData
	{
		$type  = ucfirst($this->type);
		$class = "TotalCMS\\Domain\\Property\\Data\\{$type}Data";

		if (!class_exists($class)) {
			throw new \UnexpectedValueException("Invalid file type $type found for property $property in collection $collection");
		}

		$fileProperty = new $class();

		if (!$fileProperty instanceof PropertyData) {
			throw new \DomainException('Error creating property for object.');
		}

		return $fileProperty;
	}

	protected function fetchProperty(string $collection, string $objectID, string $property): PropertyData
	{
		try {
			// Get the existing object property data
			$fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		} catch (\UnexpectedValueException) {
			$fileProperty = $this->createPropertyObject($collection, $property);
		}

		return $fileProperty;
	}

	/**
	 * Fetch the existing PropertyData for the field that's actually being saved —
	 * either the top-level property or, when $subpath is set, the child stored at
	 * `obj[$property][$subpath]` inside a CardData parent.
	 */
	protected function fetchExistingChildProperty(
		string $collection,
		string $objectID,
		string $property,
		?string $subpath,
	): PropertyData {
		if ($subpath === null || $subpath === '') {
			return $this->fetchProperty($collection, $objectID, $property);
		}

		try {
			$parent = $this->propFetcher->fetchProperty($collection, $objectID, $property);
		} catch (\UnexpectedValueException) {
			return $this->createPropertyObject($collection, $property);
		}

		// Card-nested case: parent is a CardData, child lives in `$parent->card[$subpath]`.
		// (Phase 2 only handles single-segment subpath = card child key. Deeper nesting
		// for deck items lands in Phase 3.)
		if ($parent instanceof CardData) {
			$childRaw = $parent->card[$subpath] ?? null;
			if (!is_array($childRaw)) {
				return $this->createPropertyObject($collection, $property);
			}

			return $this->buildPropertyDataFromArray($childRaw);
		}

		// Other parent types (e.g. DepotData) handle subpath in their own savers.
		return $this->createPropertyObject($collection, $property);
	}

	/**
	 * Build a typed PropertyData from a raw child array using this saver's
	 * configured `$type`. Each subclass (FileSaver, ImageSaver) inherits this.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function buildPropertyDataFromArray(array $data): PropertyData
	{
		$type  = ucfirst($this->type);
		$class = "TotalCMS\\Domain\\Property\\Data\\{$type}Data";
		if (!class_exists($class)) {
			throw new \UnexpectedValueException("Invalid file type {$type} for nested property data");
		}

		$instance = new $class($data);
		if (!$instance instanceof PropertyData) {
			throw new \DomainException('Constructed nested property data is not PropertyData');
		}

		return $instance;
	}

	/**
	 * Reject SVG uploads. Image and gallery fields are raster-only; SVG belongs
	 * in the dedicated (sanitized) SVG field. Without this, an SVG stored in an
	 * image/gallery field is served back raw as `image/svg+xml` by the public
	 * imageworks endpoint — stored XSS. Content is sniffed (not just the
	 * extension) because the served mime is content-detected, so a renamed SVG
	 * would still execute. File and depot savers do NOT call this — SVG is a
	 * legitimate file there.
	 */
	protected function assertNotSvg(string $filePath): void
	{
		if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'svg') {
			throw new \DomainException('SVG files are not supported in image or gallery fields. Use an SVG field instead.');
		}

		if (!is_file($filePath)) {
			return; // nothing to sniff; a missing upload is handled downstream
		}

		// Raster images start with binary magic bytes (never '<'); an SVG/XML
		// document starts with '<'. Anchoring on that first means a legit image
		// that merely contains the bytes "<svg" in its EXIF/XMP metadata can't be
		// false-flagged, while a renamed SVG (served back as content-detected
		// image/svg+xml → XSS) is still caught.
		$head = ltrim((string)file_get_contents($filePath, false, null, 0, 4096), "\xEF\xBB\xBF \t\r\n");
		if ($head !== '' && $head[0] === '<' && preg_match('/<svg[\s>\/]/i', $head) === 1) {
			throw new \DomainException('SVG files are not supported in image or gallery fields. Use an SVG field instead.');
		}
	}

	/**
	 * Convert a HEIC/HEIF upload to JPEG and return the JPEG path. This is the
	 * single choke point image/gallery saves flow through, so palette extraction
	 * and EXIF reading always run against a web-readable raster — never the HEIC
	 * original, which most image libraries (and browsers) can't decode.
	 *
	 * The upload action may have already converted the file; in that case it
	 * arrives as a .jpg and this is a no-op. If the server lacks HEIC support the
	 * conversion fails and we fall through with the original path unchanged — the
	 * upload still succeeds, palette/EXIF degrade gracefully.
	 */
	protected function convertHeicToJpeg(string $filePath): string
	{
		$converter = new \TotalCMS\Domain\Media\Service\HeicConverter();
		if (!$converter->isHeicFile($filePath)) {
			return $filePath;
		}

		$result = $converter->convertAndReplace($filePath);
		if ($result->success && isset($result->data['path']) && is_string($result->data['path'])) {
			return $result->data['path'];
		}

		$this->getLogger()->warning('HEIC conversion unavailable; processing original file', [
			'file'  => $filePath,
			'error' => $result->message,
		]);

		return $filePath;
	}

	protected function updateObject(
		string $collection,
		string $objectID,
		string $property,
		PropertyData $data,
		?string $subpath = null,
	): ObjectData {
		if ($subpath !== null && $subpath !== '') {
			// Nested write — patch into `obj[$property][$subpath]` so card siblings
			// are preserved.
			return $this->objectPatcher->patchNestedProperty(
				$collection,
				$objectID,
				$property,
				$subpath,
				$data->transform(),
			);
		}

		$propertyData = [$property => $data->transform()];

		return $this->objectPatcher->patchObject($collection, $objectID, $propertyData);
	}
}

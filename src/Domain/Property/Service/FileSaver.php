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

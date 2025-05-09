<?php

namespace TotalCMS\Domain\Object\Repository;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Schema\Service\SchemaValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

final class ObjectRepository extends StorageRepository
{
	private ObjectFactory $factory;
	private SchemaValidator $validator;
	private CollectionFetcher $collectionFetcher;

	public function __construct(
		StorageAdapterInterface $filesystem,
		ObjectFactory $factory,
		SchemaValidator $validator,
		CollectionFetcher $collectionFetcher
	) {
		parent::__construct($filesystem);

		$this->factory           = $factory;
		$this->validator         = $validator;
		$this->collectionFetcher = $collectionFetcher;
	}

	/**
	 * Save an object.
	 *
	 * @param string $collection
	 * @param ObjectData $object
	 *
	 * @return void
	 */
	public function saveObject(string $collection, ObjectData $object): void
	{
		if (in_array($object->id, ObjectData::RESERVED_NAMES)) {
			throw new \UnexpectedValueException('Cannot save object with a reserved name:' . $object->id);
		}

		$collectionInfo = $this->collectionFetcher->fetchCollection($collection);

		if ($collectionInfo === null) {
			throw new \UnexpectedValueException('Collection not found: ' . $collection);
		}

		if ($this->validator->validateSchema($object->toArray(), $collectionInfo->schema) === false) {
			throw new \UnexpectedValueException('Invalid object data provided. Failed schema validation.', 1);
		}

		$objectFile = $this->buildObjectPath($collection, $object->id);

		$this->filesystem->write($objectFile, $object->toJson());
	}

	public function existsObject(string $collection, string $id): bool
	{
		$objectFile = $this->buildObjectPath($collection, $id);

		return $this->filesystem->fileExists($objectFile);
	}

	public function fetchObject(string $collection, string $id): ?ObjectData
	{
		$objectFile = $this->buildObjectPath($collection, $id);

		if ($this->filesystem->fileExists($objectFile)) {
			$contents = json_decode($this->filesystem->read($objectFile), true);
			$object   = $this->factory->generateObject($collection, $contents);
			return $object;
		}

		return null;
	}

	public function deleteObject(string $collection, string $id): bool
	{
		$filesPath  = $this->buildObjectFilesPath($collection, $id);
		$objectFile = $this->buildObjectPath($collection, $id);

		$this->filesystem->deleteDirectory($filesPath);

		return $this->filesystem->delete($objectFile);
	}

	public function copyObjectFiles(string $fromCollection, string $fromId, string $toCollection, string $toId): void
	{
		$fromPath = $this->buildObjectFilesPath($fromCollection, $fromId);
		$toPath   = $this->buildObjectFilesPath($toCollection, $toId);

		if ($this->filesystem->directoryExists($fromPath)) {
			$this->filesystem->copyDirectory($fromPath, $toPath);
		}
	}

	private function buildObjectFilesPath(string $collection, string $id): string
	{
		return PathUtils::buildPath(collection: $collection, filename: $id);
	}

	private function buildObjectPath(string $collection, string $id): string
	{
		return PathUtils::buildPath(collection: $collection, filename: $id . self::FILE_EXT);
	}
}

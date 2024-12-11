<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;

final class SaverFactory
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
		private ObjectSaver $objectSaver,
		private CollectionSchemaFetcher $schemaFetcher,
		protected ObjectPatcher $objectPatcher,
		private ObjectFetcher $objectFetcher,
	){}

	public function generateSaverService(string $collection, string $property): FileSaver
	{
		$type = $this->getPropertyType($collection, $property);

		$className = 'TotalCMS\\Domain\\Property\\Service\\' . ucfirst($type) . 'Saver';
		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown saver service type for object.');
		}

		$saver = new $className(
			$this->storage,
			$this->propFetcher,
			$this->objectSaver,
			$this->objectPatcher,
			$this->objectFetcher
		);

		if (!$saver instanceof FileSaver) {
			throw new \DomainException('Error creating file saver service.');
		}

		return $saver;
	}

	private function getPropertyType(string $collection, string $property): string
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		return basename($schema->properties[$property]['$ref'], StorageRepository::FILE_EXT);
	}
}

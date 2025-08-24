<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Factory\LoggerFactory;

final class SaverFactory
{
	public function __construct(
		private readonly PropertyRepository $storage,
		private readonly PropertyFetcher $propFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly SchemaFetcher $schemaFetcher,
		protected ObjectPatcher $objectPatcher,
		private readonly ObjectFetcher $objectFetcher,
		protected LoggerFactory $loggerFactory,
	) {
	}

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
			$this->objectFetcher,
			$this->loggerFactory,
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

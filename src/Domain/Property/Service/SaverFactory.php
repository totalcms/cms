<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

readonly class SaverFactory
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
		private ObjectSaver $objectSaver,
		private SchemaFetcher $schemaFetcher,
		private ObjectPatcher $objectPatcher,
		private ObjectFetcher $objectFetcher,
		private LoggerFactory $loggerFactory,
		private Config $config,
		private PropertyMetaResolver $metaResolver,
	) {
	}

	public function generateSaverService(string $collection, string $property, string $objectId = ''): FileSaver
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$type   = PropertyDefinition::fromArray($schema->properties[$property])->resolveType();

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
			$this->config,
		);

		if (!$saver instanceof FileSaver) {
			throw new \DomainException('Error creating file saver service.');
		}

		$settings = $this->metaResolver->resolveSettings($collection, $property, $objectId);
		$saver->setSettings($settings);

		return $saver;
	}
}

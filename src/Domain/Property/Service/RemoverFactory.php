<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

readonly class RemoverFactory
{
	public function __construct(
		private PropertyRepository $storage,
		private PropertyFetcher $propFetcher,
		private ObjectPatcher $objectPatcher,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	public function generateRemoverService(string $collection, string $property): FileRemover
	{
		$type = $this->getPropertyType($collection, $property);

		$className = 'TotalCMS\\Domain\\Property\\Service\\' . ucfirst($type) . 'Remover';
		if (!class_exists($className)) {
			$className = FileRemover::class;
		}

		$remover = new $className(
			$this->storage,
			$this->propFetcher,
			$this->objectPatcher,
			$this->objectFetcher
		);

		if (!$remover instanceof FileRemover) {
			throw new \DomainException('Error creating file remover service.');
		}

		return $remover;
	}

	private function getPropertyType(string $collection, string $property): string
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		if (!array_key_exists($property, $schema->properties) || !is_array($schema->properties[$property])) {
			throw new \UnexpectedValueException("Property '{$property}' not found on schema for collection '{$collection}'");
		}

		return PropertyDefinition::fromArray($schema->properties[$property])->resolveType();
	}
}

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

	/**
	 * Removers taking the four-argument FileRemover constructor, by property
	 * type. Everything else falls back to FileRemover.
	 *
	 * An explicit map, NOT a class name built from the type. resolveType()
	 * falls back to a property's `type` and then its `field`, both straight
	 * from schema JSON a customer can author, so `ucfirst($type) . 'Remover'`
	 * put user input into a class name. A custom schema saying
	 * `"field": "upload"` resolved to the real UploadRemover, whose
	 * constructor takes two arguments of different types — and the resulting
	 * ArgumentCountError fired BEFORE the instanceof guard below could reject
	 * it. That error was on Sentry's ignore list, so the 500 went unreported.
	 *
	 * @var array<string,class-string<FileRemover>>
	 */
	private const REMOVERS = [
		'depot' => DepotRemover::class,
	];

	public function generateRemoverService(string $collection, string $property): FileRemover
	{
		$type = $this->getPropertyType($collection, $property);

		$className = self::REMOVERS[$type] ?? FileRemover::class;

		// No instanceof guard: REMOVERS is typed class-string<FileRemover>, so
		// the type system now guarantees what that runtime check used to guess
		// at — and it could never fire anyway, because a wrong class blew up in
		// the constructor above it.
		return new $className(
			$this->storage,
			$this->propFetcher,
			$this->objectPatcher,
			$this->objectFetcher
		);
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

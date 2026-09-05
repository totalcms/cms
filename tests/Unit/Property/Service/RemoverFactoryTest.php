<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotRemover;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * The factory used to build a class name out of the property type:
 * `ucfirst($type) . 'Remover'`. PropertyDefinition::resolveType() falls back to
 * a property's `type` and then its `field`, both of which come straight from
 * schema JSON a customer can author — so schema content chose which class was
 * instantiated. `"field": "upload"` reached the real UploadRemover, whose
 * constructor takes two arguments of different types, and the ArgumentCountError
 * that followed was on Sentry's ignore list. A 500 nobody heard about.
 */
class RemoverFactoryTest extends TestCase
{
	public function testUsesTheDedicatedRemoverForDepot(): void
	{
		$this->assertInstanceOf(DepotRemover::class, $this->factoryFor(['field' => 'depot'])->generateRemoverService('c', 'p'));
	}

	public function testFallsBackToFileRemoverForOrdinaryTypes(): void
	{
		$this->assertInstanceOf(FileRemover::class, $this->factoryFor(['field' => 'image'])->generateRemoverService('c', 'p'));
		$this->assertInstanceOf(FileRemover::class, $this->factoryFor(['field' => 'gallery'])->generateRemoverService('c', 'p'));
	}

	/**
	 * The regression: a schema-authored type that happens to name a real class
	 * in the removers' namespace must not select it.
	 */
	public function testSchemaContentCannotChooseAnArbitraryRemoverClass(): void
	{
		foreach (['upload', 'deckItem', 'string', 'object'] as $hostile) {
			$remover = $this->factoryFor(['field' => $hostile])->generateRemoverService('c', 'p');
			$this->assertInstanceOf(FileRemover::class, $remover, "schema type '{$hostile}' must not choose the class");
		}
	}

	/** @param array<string,mixed> $property */
	private function factoryFor(array $property): RemoverFactory
	{
		$schema             = new SchemaData();
		$schema->properties = ['p' => $property];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		return new RemoverFactory(
			$this->createMock(PropertyRepository::class),
			$this->createMock(PropertyFetcher::class),
			$this->createMock(ObjectPatcher::class),
			$this->createMock(ObjectFetcher::class),
			$schemaFetcher,
		);
	}
}

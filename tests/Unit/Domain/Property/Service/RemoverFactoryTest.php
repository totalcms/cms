<?php

declare(strict_types=1);

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
 * RemoverFactory picks the right "*Remover" class based on the property's
 * schema $ref type. The class-resolving trick (`ucfirst($type) . 'Remover'`)
 * is easy to break with a typo, so the tests pin the concrete mappings.
 */
describe('RemoverFactory', function (): void {
	beforeEach(function (): void {
		$this->storage       = $this->createMock(PropertyRepository::class);
		$this->propFetcher   = $this->createMock(PropertyFetcher::class);
		$this->objectPatcher = $this->createMock(ObjectPatcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);

		$this->factoryInstance = new RemoverFactory(
			$this->storage,
			$this->propFetcher,
			$this->objectPatcher,
			$this->objectFetcher,
			$this->schemaFetcher,
		);
	});

	function schemaWithProperty(string $name, string $ref): SchemaData
	{
		$s             = new SchemaData();
		$s->properties = [$name => ['$ref' => $ref]];

		return $s;
	}

	test('returns a FileRemover for a file property', function (): void {
		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->willReturn(schemaWithProperty('attachment', 'https://www.totalcms.co/schemas/properties/file.json'));

		$remover = $this->factoryInstance->generateRemoverService('blog', 'attachment');

		expect($remover)->toBeInstanceOf(FileRemover::class);
	});

	test('returns a DepotRemover for a depot property', function (): void {
		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->willReturn(schemaWithProperty('storage', 'https://www.totalcms.co/schemas/properties/depot.json'));

		$remover = $this->factoryInstance->generateRemoverService('blog', 'storage');

		expect($remover)->toBeInstanceOf(DepotRemover::class);
		// DepotRemover extends FileRemover, so the base-class assertion also holds
		expect($remover)->toBeInstanceOf(FileRemover::class);
	});

	test('falls back to FileRemover for unknown property types', function (): void {
		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->willReturn(schemaWithProperty('mystery', 'https://www.totalcms.co/schemas/properties/nonexistent.json'));

		$remover = $this->factoryInstance->generateRemoverService('blog', 'mystery');

		// No NonexistentRemover class — falls back to FileRemover base class
		expect($remover)->toBeInstanceOf(FileRemover::class);
	});
});

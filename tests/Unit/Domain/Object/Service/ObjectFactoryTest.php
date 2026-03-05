<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\PropertyFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ObjectFactoryTest extends TestCase
{
	private ObjectFactory $factory;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $propertyFactory;
	private \PHPUnit\Framework\MockObject\MockObject $autogenIdService;
	private \PHPUnit\Framework\MockObject\MockObject $autogenService;

	protected function setUp(): void
	{
		$this->schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$this->propertyFactory  = $this->createMock(PropertyFactory::class);
		$this->autogenIdService = $this->createMock(AutogenIdService::class);
		$this->autogenService   = $this->createMock(AutogenService::class);

		$this->factory = new ObjectFactory(
			$this->schemaFetcher,
			$this->propertyFactory,
			$this->autogenIdService,
			$this->autogenService
		);
	}

	public function testGenerateObjectHandlesMissingProperties(): void
	{
		// Schema with 3 properties, but object data only has 2
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'    => ['type' => 'string', 'label' => 'ID'],
			'name'  => ['type' => 'string', 'label' => 'Name'],
			'email' => ['type' => 'email', 'label' => 'Email'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		// PropertyFactory should be called with null for the missing 'email' property
		$this->propertyFactory->expects($this->exactly(2))
			->method('generateProperty')
			->willReturnCallback(fn ($propertySchema, $value): StringData =>
				// First call: name property with value
				// Second call: email property with null (missing)
				new StringData($value ?? ''));

		$objectData = [
			'id'   => 'test-1',
			'name' => 'John Doe',
			// 'email' is missing
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('test-1');
	}

	public function testGenerateObjectHandlesNullPropertyValues(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'    => ['type' => 'string', 'label' => 'ID'],
			'name'  => ['type' => 'string', 'label' => 'Name'],
			'email' => ['type' => 'email', 'label' => 'Email'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->propertyFactory->method('generateProperty')
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'id'    => 'test-2',
			'name'  => null,
			'email' => null,
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('test-2');
	}

	public function testGenerateObjectHandlesEmptyStringValues(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'    => ['type' => 'string', 'label' => 'ID'],
			'name'  => ['type' => 'string', 'label' => 'Name'],
			'email' => ['type' => 'email', 'label' => 'Email'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->propertyFactory->method('generateProperty')
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'id'    => 'test-3',
			'name'  => '',
			'email' => '',
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('test-3');
	}

	public function testGenerateObjectHandlesMixedNullEmptyAndValidValues(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'          => ['type' => 'string', 'label' => 'ID'],
			'name'        => ['type' => 'string', 'label' => 'Name'],
			'email'       => ['type' => 'email', 'label' => 'Email'],
			'description' => ['type' => 'string', 'label' => 'Description'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->propertyFactory->method('generateProperty')
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'id'          => 'test-4',
			'name'        => 'Jane Doe',
			'email'       => null,
			'description' => '',
			// Some property could be missing
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('test-4');
	}

	public function testGenerateObjectThrowsExceptionWhenIdMissingAndNoAutogen(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'   => ['type' => 'string', 'label' => 'ID'],
			'name' => ['type' => 'string', 'label' => 'Name'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->autogenIdService->method('generateId')
			->willReturn(''); // Autogen fails to generate ID

		$objectData = [
			'name' => 'No ID Object',
			// 'id' is missing and autogen returns empty
		];

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Object data must contain an ID or schema must have autogen settings for ID field.');

		$this->factory->generateObject('test-collection', $objectData);
	}

	public function testGenerateObjectGeneratesIdWhenMissing(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'   => [
				'type'     => 'string',
				'label'    => 'ID',
				'settings' => ['autogen' => '${name}'],
			],
			'name' => ['type' => 'string', 'label' => 'Name'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->autogenIdService->method('generateId')
			->willReturn('generated-id');

		$this->propertyFactory->method('generateProperty')
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'name' => 'John Doe',
			// 'id' is missing, should be auto-generated
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('generated-id');
	}

	public function testGenerateObjectHandlesEmptyIdByGenerating(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'   => [
				'type'     => 'string',
				'label'    => 'ID',
				'settings' => ['autogen' => '${name}'],
			],
			'name' => ['type' => 'string', 'label' => 'Name'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		$this->autogenIdService->method('generateId')
			->willReturn('autogen-from-name');

		$this->propertyFactory->method('generateProperty')
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'id'   => '', // Empty ID should trigger autogen
			'name' => 'Test Name',
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('autogen-from-name');
	}

	public function testGenerateObjectHandlesAllPropertiesMissing(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'test-schema';
		$schema->properties = [
			'id'    => ['type' => 'string', 'label' => 'ID'],
			'name'  => ['type' => 'string', 'label' => 'Name'],
			'email' => ['type' => 'email', 'label' => 'Email'],
		];

		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($schema);

		// PropertyFactory should be called with null for all missing properties
		$this->propertyFactory->expects($this->exactly(2))
			->method('generateProperty')
			->with(
				$this->anything(),
				$this->identicalTo(null)
			)
			->willReturnCallback(fn ($schema, $value): StringData => new StringData($value ?? ''));

		$objectData = [
			'id' => 'test-5',
			// All other properties are missing
		];

		$object = $this->factory->generateObject('test-collection', $objectData);

		expect($object)->toBeInstanceOf(ObjectData::class);
		expect($object->id)->toBe('test-5');
	}
}

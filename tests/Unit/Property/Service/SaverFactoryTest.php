<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Tests for SaverFactory - critical factory for creating property saver services.
 */
class SaverFactoryTest extends TestCase
{
	private SaverFactory $saverFactory;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropertyRepository;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropertyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockSchemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockLoggerFactory;

	protected function setUp(): void
	{
		$this->mockPropertyRepository = $this->createMock(PropertyRepository::class);
		$this->mockPropertyFetcher    = $this->createMock(PropertyFetcher::class);
		$this->mockObjectSaver        = $this->createMock(ObjectSaver::class);
		$this->mockSchemaFetcher      = $this->createMock(SchemaFetcher::class);
		$this->mockObjectPatcher      = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher      = $this->createMock(ObjectFetcher::class);
		$this->mockLoggerFactory      = $this->createMock(LoggerFactory::class);

		$this->saverFactory = new SaverFactory(
			$this->mockPropertyRepository,
			$this->mockPropertyFetcher,
			$this->mockObjectSaver,
			$this->mockSchemaFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher,
			$this->mockLoggerFactory,
			$this->createMock(Config::class),
		);
	}

	public function testGenerateSaverServiceForFileProperty(): void
	{
		// Mock schema with file property
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'document' => [
				'$ref' => 'https://www.totalcms.co/schemas/file.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'document');

		$this->assertInstanceOf(FileSaver::class, $saver);
		$this->assertEquals('file', $saver->type);
	}

	public function testGenerateSaverServiceForImageProperty(): void
	{
		// Mock schema with image property
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'photo' => [
				'$ref' => 'https://www.totalcms.co/schemas/image.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'photo');

		// Should create ImageSaver but return it as FileSaver interface
		$this->assertInstanceOf(FileSaver::class, $saver);
	}

	public function testGenerateSaverServiceForDepotProperty(): void
	{
		// Mock schema with depot property
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'files' => [
				'$ref' => 'https://www.totalcms.co/schemas/depot.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'files');

		// Should create DepotSaver
		$this->assertInstanceOf(FileSaver::class, $saver);
	}

	public function testGenerateSaverServiceForGalleryProperty(): void
	{
		// Mock schema with gallery property
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'images' => [
				'$ref' => 'https://www.totalcms.co/schemas/gallery.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'images');

		// Should create GallerySaver
		$this->assertInstanceOf(FileSaver::class, $saver);
	}

	public function testGenerateSaverServiceThrowsExceptionForUnknownType(): void
	{
		// Mock schema with unknown property type
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'unknown_field' => [
				'$ref' => 'https://www.totalcms.co/schemas/unknowntype.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unknown saver service type for object.');

		$this->saverFactory->generateSaverService('test-collection', 'unknown_field');
	}

	public function testGenerateSaverServiceWithCustomSchemaUrl(): void
	{
		// Mock schema with custom schema URL structure
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'custom_file' => [
				'$ref' => 'https://www.example.com/schemas/file.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'custom_file');

		$this->assertInstanceOf(FileSaver::class, $saver);
		$this->assertEquals('file', $saver->type);
	}

	public function testGenerateSaverServiceHandlesComplexPropertyNames(): void
	{
		// Test with various property names
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'my_complex_file_property' => [
				'$ref' => 'https://www.totalcms.co/schemas/file.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->with('test-collection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'my_complex_file_property');

		$this->assertInstanceOf(FileSaver::class, $saver);
	}

	public function testGenerateSaverServicePassesAllDependencies(): void
	{
		// Mock schema
		$mockSchema             = new SchemaData();
		$mockSchema->properties = [
			'document' => [
				'$ref' => 'https://www.totalcms.co/schemas/file.json',
			],
		];

		$this->mockSchemaFetcher
			->expects($this->once())
			->method('fetchSchemaForCollection')
			->willReturn($mockSchema);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'document');

		$this->assertInstanceOf(FileSaver::class, $saver);

		// Test that the saver was created with all the correct dependencies
		// We can't easily test the private properties, but we can verify it was constructed properly
		$reflection = new \ReflectionClass($saver);
		$this->assertTrue($reflection->hasProperty('storage'));
		$this->assertTrue($reflection->hasProperty('propFetcher'));
		$this->assertTrue($reflection->hasProperty('objectSaver'));
		$this->assertTrue($reflection->hasProperty('objectPatcher'));
		$this->assertTrue($reflection->hasProperty('objectFetcher'));
		$this->assertTrue($reflection->hasProperty('loggerFactory'));
	}

	public function testGetPropertyTypeExtractsCorrectType(): void
	{
		// Test that different schema URLs produce correct types
		$testCases = [
			'file'    => 'file.json',
			'image'   => 'image.json',
			'depot'   => 'depot.json',
			'gallery' => 'gallery.json',
		];

		foreach ($testCases as $expectedType => $schemaFile) {
			// Create a fresh mock for each iteration
			$this->setUp();

			$mockSchema             = new SchemaData();
			$mockSchema->properties = [
				'test_prop' => [
					'$ref' => "https://www.totalcms.co/schemas/$schemaFile",
				],
			];

			$this->mockSchemaFetcher
				->expects($this->once())
				->method('fetchSchemaForCollection')
				->with('test-collection')
				->willReturn($mockSchema);

			$saver = $this->saverFactory->generateSaverService('test-collection', 'test_prop');
			$this->assertInstanceOf(FileSaver::class, $saver);
			$this->assertEquals($expectedType, $saver->type);
		}
	}
}

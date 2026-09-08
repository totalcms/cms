<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\GallerySaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
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
	private MockObject $mockPropertyRepository;
	private MockObject $mockPropertyFetcher;
	private MockObject $mockObjectSaver;
	private MockObject $mockSchemaFetcher;
	private MockObject $mockObjectPatcher;
	private MockObject $mockObjectFetcher;
	private MockObject $mockLoggerFactory;
	private MockObject $mockMetaResolver;

	protected function setUp(): void
	{
		$this->mockPropertyRepository = $this->createMock(PropertyRepository::class);
		$this->mockPropertyFetcher    = $this->createMock(PropertyFetcher::class);
		$this->mockObjectSaver        = $this->createMock(ObjectSaver::class);
		$this->mockSchemaFetcher      = $this->createMock(SchemaFetcher::class);
		$this->mockObjectPatcher      = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher      = $this->createMock(ObjectFetcher::class);
		$this->mockLoggerFactory      = $this->createMock(LoggerFactory::class);
		$this->mockMetaResolver       = $this->createMock(PropertyMetaResolver::class);

		$this->mockMetaResolver
			->method('resolveSettings')
			->willReturn([]);

		$this->saverFactory = new SaverFactory(
			$this->mockPropertyRepository,
			$this->mockPropertyFetcher,
			$this->mockObjectSaver,
			$this->mockSchemaFetcher,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher,
			$this->mockLoggerFactory,
			$this->createMock(Config::class),
			$this->mockMetaResolver,
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

	public function testGenerateSaverServiceWithSubpathResolvesNestedChildType(): void
	{
		// Card-nested upload: URL says property=mycard, but the actual saver
		// type comes from the card's child schema (image, in this case).
		// SaverFactory still consults the parent schema to confirm the parent
		// isn't a depot/gallery (those own their own subpath semantics).
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['mycard' => ['field' => 'card', '$ref' => 'https://www.totalcms.co/schemas/properties/card.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		// resolveNested() already returns the child's resolved settings, so the
		// walker reads them off that result rather than making a second lookup.
		$this->mockMetaResolver
			->expects($this->once())
			->method('resolveNested')
			->with('test-collection', 'mycard', 'image')
			->willReturn(['field' => 'image', 'type' => 'image', 'settings' => ['quality' => 70]]);

		$this->mockMetaResolver->expects($this->never())->method('resolveNestedSettings');

		$saver = $this->saverFactory->generateSaverService(
			'test-collection',
			'mycard',
			'',
			'image', // subpath = child key
		);

		$this->assertInstanceOf(ImageSaver::class, $saver);
		$this->assertEquals('image', $saver->type);
		$this->assertSame(['quality' => 70], $this->settingsOf($saver));
	}

	public function testGenerateSaverServiceWithMultiSegmentSubpathUsesLastSegment(): void
	{
		// Deck-style subpath: `item-3/image`. The first segment is the item id
		// (skipped); the child type comes from the schemaref child "image".
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['mydeck' => ['field' => 'deck', '$ref' => 'https://www.totalcms.co/schemas/properties/deck.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->mockMetaResolver
			->expects($this->once())
			->method('resolveNested')
			->with('test-collection', 'mydeck', 'image')
			->willReturn(['field' => 'file', 'type' => 'file']);

		$this->mockMetaResolver->expects($this->never())->method('resolveNestedSettings');

		$saver = $this->saverFactory->generateSaverService(
			'test-collection',
			'mydeck',
			'',
			'item-3/image',
		);

		$this->assertInstanceOf(FileSaver::class, $saver);
		$this->assertEquals('file', $saver->type);
	}

	public function testGenerateSaverServiceWalksThroughAVideoInsideACardToItsPoster(): void
	{
		// `mycard/promo/poster`: the card's schemaref says `promo` is a video;
		// a video's only child is `poster`, an image configured by the video's
		// own `settings.poster`. No second schemaref hop is consulted.
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['mycard' => ['field' => 'card', '$ref' => 'https://www.totalcms.co/schemas/properties/card.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->mockMetaResolver
			->expects($this->once())
			->method('resolveNested')
			->with('test-collection', 'mycard', 'promo')
			->willReturn(['field' => 'video', 'type' => 'video', 'settings' => ['poster' => ['quality' => 55]]]);

		$saver = $this->saverFactory->generateSaverService('test-collection', 'mycard', '', 'promo/poster');

		$this->assertInstanceOf(ImageSaver::class, $saver);
		$this->assertSame(['quality' => 55], $this->settingsOf($saver));
	}

	public function testGenerateSaverServiceRejectsANonPosterChildOfANestedVideo(): void
	{
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['mycard' => ['field' => 'card', '$ref' => 'https://www.totalcms.co/schemas/properties/card.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);
		$this->mockMetaResolver->method('resolveNested')->willReturn(['field' => 'video', 'type' => 'video']);

		$this->expectException(\UnexpectedValueException::class);
		$this->saverFactory->generateSaverService('test-collection', 'mycard', '', 'promo/thumbnail');
	}

	/** FileSaver keeps its settings protected; read them back for assertions. */
	private function settingsOf(FileSaver $saver): array
	{
		$property = new \ReflectionProperty(FileSaver::class, 'settings');

		return (array)$property->getValue($saver);
	}

	public function testGenerateSaverServiceWithSubpathOnDepotKeepsDepotSaver(): void
	{
		// Depot owns its `subpath` semantics — uploads to `?path=subfolder` should
		// not be treated as nested-child resolution. The DepotSaver receives the
		// subpath unchanged. Regression: this used to fall into the nested branch
		// and throw `Parent property has no schemaref` for every depot-folder upload.
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['depot' => ['field' => 'depot', '$ref' => 'https://www.totalcms.co/schemas/properties/depot.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->mockMetaResolver->expects($this->never())->method('resolveNested');
		$this->mockMetaResolver->expects($this->never())->method('resolveNestedSettings');

		$saver = $this->saverFactory->generateSaverService(
			'test-collection',
			'depot',
			'',
			'subfolder',
		);

		$this->assertInstanceOf(DepotSaver::class, $saver);
		$this->assertEquals('depot', $saver->type);
	}

	public function testGenerateSaverServiceWithSubpathOnGalleryKeepsGallerySaver(): void
	{
		// Same exception as depot — gallery owns its own folder subpath.
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['photos' => ['field' => 'gallery', '$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json']];
		$this->mockSchemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->mockMetaResolver->expects($this->never())->method('resolveNested');

		$saver = $this->saverFactory->generateSaverService(
			'test-collection',
			'photos',
			'',
			'2024/spring',
		);

		$this->assertInstanceOf(GallerySaver::class, $saver);
	}
}

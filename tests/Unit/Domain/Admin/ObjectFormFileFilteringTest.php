<?php

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\ObjectForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

/**
 * Test ObjectForm file property filtering for object duplication.
 * When duplicating objects, file-based properties (file, image, depot, gallery)
 * should be filtered out, but svg properties should be kept.
 */
describe('ObjectForm File Property Filtering', function (): void {
	beforeEach(function (): void {
		// Mock dependencies
		$this->objectFetcher            = $this->createMock(ObjectFetcher::class);
		$this->collectionFetcher        = $this->createMock(CollectionFetcher::class);
		$this->collectionLister         = $this->createMock(CollectionLister::class);
		$this->indexReader              = $this->createMock(IndexReader::class);
		$this->indexFilter              = $this->createMock(IndexFilter::class);
		$this->schemaFetcher            = $this->createMock(SchemaFetcher::class);
		$this->schemaLister             = $this->createMock(SchemaLister::class);
		$this->accessGroupLister        = $this->createMock(AccessGroupLister::class);
		$this->collectionEditionService = $this->createMock(CollectionEditionService::class);
		$this->editionFeatures          = $this->createMock(EditionFeatureService::class);
		$this->csrfManager              = $this->createMock(CSRFTokenManager::class);
		$this->config                   = Config::init();
		$this->metaResolver             = $this->createMock(PropertyMetaResolver::class);

		// Create mock schema with various property types
		$this->schemaData             = new SchemaData();
		$this->schemaData->id         = 'test-schema';
		$this->schemaData->properties = [
			'id'          => ['type' => 'string'],
			'title'       => ['type' => 'string'],
			'description' => ['type' => 'string'],
			'tags'        => ['type' => 'array'],
			// File-based properties that should be filtered
			'image'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
			'file'        => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'],
			'depot'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json'],
			'gallery'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
			// SVG should NOT be filtered
			'svg'         => ['$ref' => 'https://www.totalcms.co/schemas/properties/svg.json'],
			// Other types should not be filtered
			'number'      => ['type' => 'number'],
			'boolean'     => ['type' => 'boolean'],
		];
		$this->schemaData->required = [];

		// Create mock collection
		$this->collectionData         = new CollectionData();
		$this->collectionData->id     = 'test-collection';
		$this->collectionData->schema = 'test-schema';

		// Configure mocks
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaData);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
		$this->objectFetcher->method('existsObject')->willReturn(false);
	});

	test('filters out file property from duplicate data', function (): void {
		$duplicateData = [
			'id'    => 'original-id',
			'title' => 'Test Title',
			'file'  => ['src' => '/uploads/document.pdf', 'name' => 'document.pdf'],
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		// Use reflection to access the private duplicateData property
		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// File property should be removed
		expect($filteredDuplicateData)->not->toHaveKey('file');
		// Other properties should remain
		expect($filteredDuplicateData)->toHaveKey('title');
		expect($filteredDuplicateData['title'])->toBe('Test Title');
		// ID should be blanked
		expect($filteredDuplicateData['id'])->toBe('');
	});

	test('filters out image property from duplicate data', function (): void {
		$duplicateData = [
			'id'    => 'original-id',
			'title' => 'Test Title',
			'image' => ['src' => '/uploads/photo.jpg', 'alt' => 'Photo'],
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		expect($filteredDuplicateData)->not->toHaveKey('image');
		expect($filteredDuplicateData)->toHaveKey('title');
	});

	test('filters out depot property from duplicate data', function (): void {
		$duplicateData = [
			'id'    => 'original-id',
			'title' => 'Test Title',
			'depot' => ['files' => ['/depot/file1.pdf', '/depot/file2.pdf']],
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		expect($filteredDuplicateData)->not->toHaveKey('depot');
	});

	test('filters out gallery property from duplicate data', function (): void {
		$duplicateData = [
			'id'      => 'original-id',
			'title'   => 'Test Title',
			'gallery' => ['images' => ['/gallery/img1.jpg', '/gallery/img2.jpg']],
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		expect($filteredDuplicateData)->not->toHaveKey('gallery');
	});

	test('keeps svg property in duplicate data', function (): void {
		$svgContent    = '<svg><rect width="100" height="100"/></svg>';
		$duplicateData = [
			'id'    => 'original-id',
			'title' => 'Test Title',
			'svg'   => $svgContent,
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// SVG should NOT be filtered out
		expect($filteredDuplicateData)->toHaveKey('svg');
		expect($filteredDuplicateData['svg'])->toBe($svgContent);
	});

	test('keeps non-file properties in duplicate data', function (): void {
		$duplicateData = [
			'id'          => 'original-id',
			'title'       => 'Test Title',
			'description' => 'Test Description',
			'tags'        => ['tag1', 'tag2', 'tag3'],
			'number'      => 42,
			'boolean'     => true,
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// All non-file properties should be preserved
		expect($filteredDuplicateData)->toHaveKey('title');
		expect($filteredDuplicateData)->toHaveKey('description');
		expect($filteredDuplicateData)->toHaveKey('tags');
		expect($filteredDuplicateData)->toHaveKey('number');
		expect($filteredDuplicateData)->toHaveKey('boolean');

		expect($filteredDuplicateData['title'])->toBe('Test Title');
		expect($filteredDuplicateData['description'])->toBe('Test Description');
		expect($filteredDuplicateData['tags'])->toBe(['tag1', 'tag2', 'tag3']);
		expect($filteredDuplicateData['number'])->toBe(42);
		expect($filteredDuplicateData['boolean'])->toBeTrue();
	});

	test('filters multiple file properties at once', function (): void {
		$duplicateData = [
			'id'          => 'original-id',
			'title'       => 'Test Title',
			'image'       => ['src' => '/uploads/photo.jpg'],
			'file'        => ['src' => '/uploads/document.pdf'],
			'depot'       => ['files' => ['/depot/file1.pdf']],
			'gallery'     => ['images' => ['/gallery/img1.jpg']],
			'svg'         => '<svg></svg>',
			'description' => 'Keep this',
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// File-based properties should be removed
		expect($filteredDuplicateData)->not->toHaveKey('image');
		expect($filteredDuplicateData)->not->toHaveKey('file');
		expect($filteredDuplicateData)->not->toHaveKey('depot');
		expect($filteredDuplicateData)->not->toHaveKey('gallery');

		// Non-file properties should remain
		expect($filteredDuplicateData)->toHaveKey('svg');
		expect($filteredDuplicateData)->toHaveKey('title');
		expect($filteredDuplicateData)->toHaveKey('description');
	});

	test('blanks ID field for autogen rules', function (): void {
		$duplicateData = [
			'id'    => 'specific-id-12345',
			'title' => 'Test Title',
		];

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// ID should be blanked to allow autogen rules to work
		expect($filteredDuplicateData['id'])->toBe('');
	});

	test('handles empty data array gracefully', function (): void {
		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			data: []
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// Should return empty array
		expect($filteredDuplicateData)->toBe([]);
	});

	test('filtering only applies when ID is empty', function (): void {
		// When editing an existing object (ID is set), filtering should NOT occur
		$duplicateData = [
			'id'    => 'original-id',
			'title' => 'Test Title',
			'image' => ['src' => '/uploads/photo.jpg'],
		];

		// Simulate editing mode by having an existing object
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$form = new ObjectForm(
			objectFetcher: $this->objectFetcher,
			collectionFetcher: $this->collectionFetcher,
			collectionLister: $this->collectionLister,
			collectionReader: $this->indexReader,
			indexFilter: $this->indexFilter,
			schemaFetcher: $this->schemaFetcher,
			schemaLister: $this->schemaLister,
			accessGroupLister: $this->accessGroupLister,
			collectionEditionService: $this->collectionEditionService,
			editionFeatures: $this->editionFeatures,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'test-collection',
			id: 'existing-object-id', // Explicitly set ID for editing
			data: $duplicateData
		);

		$reflection             = new ReflectionClass($form);
		$duplicateDataProperty  = $reflection->getProperty('duplicateData');
		$filteredDuplicateData  = $duplicateDataProperty->getValue($form);

		// When ID is set (editing mode), duplicate data should not be processed
		// duplicateData is only set when id is empty
		expect($filteredDuplicateData)->toBe([]);
	});
});

<?php

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\ObjectForm;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Test addOnly security feature for forms to prevent editing existing objects
 * when forms are placed on the public side of websites.
 */
describe('Form AddOnly Security Feature', function (): void {
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

		// Mock existing object
		$this->existingObject = $this->createMock(ObjectData::class);
		$this->existingObject->method('toArray')->willReturn(['id' => 'existing-123', 'name' => 'Existing Object']);
	});

	afterEach(function (): void {
		// Clean up any $_GET modifications
		unset($_GET['id']);
	});

	test('regular form uses ID from URL parameter', function (): void {
		$_GET['id'] = 'test-id-123';

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')->willReturn($this->existingObject);

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
			api: '/api',
			collection: 'users',
			addOnly: false  // Regular form behavior
		);

		// Access the protected id property using reflection
		$reflection = new ReflectionClass($form);
		$idProperty = $reflection->getProperty('id');

		expect($idProperty->getValue($form))->toBe('test-id-123');
		expect($form->objectData)->not()->toBeNull();
	});

	test('addOnly form ignores ID from URL parameter', function (): void {
		$_GET['id'] = 'malicious-id-456';

		// Even if object exists, it should not be loaded
		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')->willReturn($this->existingObject);

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
			api: '/api',
			collection: 'users',
			addOnly: true  // Security: Add only mode
		);

		// Access the protected id property using reflection
		$reflection = new ReflectionClass($form);
		$idProperty = $reflection->getProperty('id');

		expect($idProperty->getValue($form))->toBe('');
		expect($form->objectData)->toBeNull();
	});

	test('addOnly form ignores explicit ID parameter', function (): void {
		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')->willReturn($this->existingObject);

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
			api: '/api',
			collection: 'users',
			id: 'explicit-id-789',  // Explicitly passed ID
			addOnly: true
		);

		// Access the protected id property using reflection
		$reflection = new ReflectionClass($form);
		$idProperty = $reflection->getProperty('id');

		expect($idProperty->getValue($form))->toBe('');
		expect($form->objectData)->toBeNull();
	});

	test('addOnly form creates POST route not PUT route', function (): void {
		$_GET['id'] = 'some-id';

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')->willReturn($this->existingObject);

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
			api: '/api',
			collection: 'users',
			addOnly: true
		);

		// Access the protected route and method properties using reflection
		$reflection = new ReflectionClass($form);

		$routeProperty = $reflection->getProperty('route');

		$methodProperty = $reflection->getProperty('method');

		// Should be POST route for new objects, not PUT route for editing
		expect($routeProperty->getValue($form))->toBe('/collections/users');
		expect($methodProperty->getValue($form))->toBe('POST');
	});

	test('regular form with existing ID creates PUT route', function (): void {
		$_GET['id'] = 'existing-user-123';

		$this->objectFetcher->method('existsObject')->willReturn(true);
		$this->objectFetcher->method('fetchObject')->willReturn($this->existingObject);

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
			api: '/api',
			collection: 'users',
			addOnly: false  // Regular form
		);

		// Access the protected route and method properties using reflection
		$reflection = new ReflectionClass($form);

		$routeProperty = $reflection->getProperty('route');

		$methodProperty = $reflection->getProperty('method');

		// Should be PUT route for editing existing objects
		expect($routeProperty->getValue($form))->toBe('/collections/users/existing-user-123');
		expect($methodProperty->getValue($form))->toBe('PUT');
	});

	test('addOnly defaults to false for backwards compatibility', function (): void {
		// Create form without specifying addOnly parameter
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
			api: '/api',
			collection: 'users'
			// No addOnly parameter - should default to false
		);

		// Access the protected addOnly property using reflection
		$reflection      = new ReflectionClass($form);
		$addOnlyProperty = $reflection->getProperty('addOnly');

		expect($addOnlyProperty->getValue($form))->toBeFalse();
	});
});

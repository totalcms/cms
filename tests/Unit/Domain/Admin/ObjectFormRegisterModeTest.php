<?php

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\ObjectForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

/**
 * `register: true` retargets the form at the public-registration endpoint
 * (POST /admin/register/{collection}) and forces addOnly so the JS can't
 * flip into edit/PUT mode.
 */
describe('ObjectForm register mode', function (): void {
	beforeEach(function (): void {
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
		$this->dataViewFilter           = $this->createMock(DataViewFilter::class);

		$this->schemaData             = new SchemaData();
		$this->schemaData->id         = 'members-schema';
		$this->schemaData->properties = [
			'id'       => ['type' => 'string'],
			'email'    => ['type' => 'string'],
			'password' => ['type' => 'string'],
		];
		$this->schemaData->required = [];

		$this->collectionData         = new CollectionData();
		$this->collectionData->id     = 'members';
		$this->collectionData->schema = 'members-schema';

		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaData);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
		// Even if some object with id 'whatever' exists, register mode must
		// not flip into edit mode — verified below.
		$this->objectFetcher->method('existsObject')->willReturn(true);

		// Pin the config's api prefix so the route assertion is deterministic
		// across run environments.
		$this->config->api = '';
	});

	test('retargets route to /admin/register/{collection}', function (): void {
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
			dataViewFilter: $this->dataViewFilter,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'members',
			register: true,
		);

		$reflection = new ReflectionClass($form);

		$route = $reflection->getProperty('route')->getValue($form);
		expect($route)->toBe('/admin/register/members');

		// `data-api` should drop the /api prefix — /admin/register lives at the
		// config base, not under the API prefix.
		$api = $reflection->getProperty('api')->getValue($form);
		expect($api)->toBe('');
	});

	test('forces addOnly regardless of how it was passed', function (): void {
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
			dataViewFilter: $this->dataViewFilter,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'members',
			// Even if the caller explicitly disabled addOnly, register mode
			// must win — there's no PUT endpoint to flip into.
			addOnly: false,
			register: true,
		);

		$reflection = new ReflectionClass($form);
		expect($reflection->getProperty('addOnly')->getValue($form))->toBeTrue();
	});

	test('does not load existing object even if id is provided', function (): void {
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
			dataViewFilter: $this->dataViewFilter,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'members',
			id: 'someone',
			register: true,
		);

		$reflection = new ReflectionClass($form);

		// addOnly is set before parent::init() runs, so the parent's $_GET['id']
		// auto-load path also never fires. Route should still be the registration
		// endpoint — never the /collections/{id} edit route.
		expect($reflection->getProperty('route')->getValue($form))->toBe('/admin/register/members');
		expect($reflection->getProperty('id')->getValue($form))->toBe('');
		expect($reflection->getProperty('method')->getValue($form))->not->toBe('PUT');
	});

	test('does not interfere with normal builder when register is false', function (): void {
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
			dataViewFilter: $this->dataViewFilter,
			csrfManager: $this->csrfManager,
			config: $this->config,
			metaResolver: $this->metaResolver,
			api: '/api',
			collection: 'members',
		);

		$reflection = new ReflectionClass($form);
		expect($reflection->getProperty('route')->getValue($form))->toBe('/collections/members');
		expect($reflection->getProperty('api')->getValue($form))->toBe('/api');
	});
});

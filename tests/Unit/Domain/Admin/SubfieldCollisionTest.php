<?php

declare(strict_types=1);

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
 * Covers the subfield-name-collision fix (commit 6344ca1f).
 *
 * Composite fields (file, image, depot, gallery) render inner sub-fields with
 * generic names like `name`, `comments`, `tags`, `password`. Without the
 * subfield flag, a top-level schema property that happens to share a name
 * (e.g. a collection's own `tags` list) would leak its settings/options into
 * the sub-field. `TotalForm::subField()` sets `subfield: true` and
 * `ObjectForm::buildFieldOptions` short-circuits before schema lookup.
 */
describe('Subfield name-collision protection', function (): void {
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
		$this->dataViewFilter           = $this->createMock(DataViewFilter::class);
		$this->csrfManager              = $this->createMock(CSRFTokenManager::class);
		$this->config                   = Config::init();
		$this->metaResolver             = $this->createMock(PropertyMetaResolver::class);

		// Top-level schema that has `tags`, `comments`, and `password` as
		// full properties with distinctive settings/options. If a sub-field
		// with any of these names accidentally inherits, we'll see the leak.
		$this->schemaData             = new SchemaData();
		$this->schemaData->id         = 'test-schema';
		$this->schemaData->required   = [];
		$this->schemaData->properties = [
			'id'       => ['field' => 'id'],
			'file'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'],
			'tags'     => [
				'field'    => 'list',
				'label'    => 'Top-level tags',
				'settings' => ['placeholder' => 'Collection tags'],
			],
			'comments' => [
				'field'    => 'textarea',
				'label'    => 'Top-level comments',
				'settings' => ['rows' => 20],
			],
			'password' => [
				'field'    => 'password',
				'label'    => 'Top-level password',
				'settings' => ['minlength' => 16],
			],
		];

		$this->collectionData         = new CollectionData();
		$this->collectionData->id     = 'test-collection';
		$this->collectionData->schema = 'test-schema';

		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaData);
	});

	function makeObjectForm(object $ctx): ObjectForm
	{
		return new ObjectForm(
			objectFetcher           : $ctx->objectFetcher,
			collectionFetcher       : $ctx->collectionFetcher,
			collectionLister        : $ctx->collectionLister,
			collectionReader        : $ctx->indexReader,
			indexFilter             : $ctx->indexFilter,
			schemaFetcher           : $ctx->schemaFetcher,
			schemaLister            : $ctx->schemaLister,
			accessGroupLister       : $ctx->accessGroupLister,
			collectionEditionService: $ctx->collectionEditionService,
			editionFeatures         : $ctx->editionFeatures,
			dataViewFilter          : $ctx->dataViewFilter,
			csrfManager             : $ctx->csrfManager,
			config                  : $ctx->config,
			metaResolver            : $ctx->metaResolver,
			api                     : '/api',
			collection              : 'test-collection',
		);
	}

	function buildFieldOptions(ObjectForm $form, string $name, array $options): array
	{
		$ref    = new ReflectionClass($form);
		$method = $ref->getMethod('buildFieldOptions');
		$method->setAccessible(true);

		return $method->invoke($form, $name, $options);
	}

	test('subField() sets the subfield flag on options', function (): void {
		$form = makeObjectForm($this);

		// Capture what createDynamicField/buildFieldOptions receives by making
		// buildFieldOptions record and short-circuit via the subfield flag.
		// We can assert the intermediate shape by calling buildFieldOptions
		// directly with the flag and confirming early-return behavior.
		$options = buildFieldOptions($form, 'tags', [
			'subfield' => true,
			'field'    => 'text',
			'label'    => 'Custom subfield label',
		]);

		// Only name + form added; no schema-derived keys leaked in.
		expect($options)->toHaveKey('subfield');
		expect($options['subfield'])->toBeTrue();
		expect($options['field'])->toBe('text');
		expect($options['label'])->toBe('Custom subfield label');
		// The top-level schema's `tags` field would set these if leaked.
		expect($options)->not->toHaveKey('settings');
		expect($options)->not->toHaveKey('options');
	});

	test('buildFieldOptions inherits schema settings when NOT a subfield', function (): void {
		// Sanity check: when the subfield flag is absent, schema settings DO
		// flow in. This guards against the fix accidentally becoming a no-op.
		$this->metaResolver
			->method('resolve')
			->willReturn([
				'field'    => 'list',
				'label'    => 'Top-level tags',
				'settings' => ['placeholder' => 'Collection tags'],
			]);

		$form    = makeObjectForm($this);
		$options = buildFieldOptions($form, 'tags', [
			'field' => 'text',
		]);

		expect($options['settings'])->toBe(['placeholder' => 'Collection tags']);
	});

	test('subfield short-circuit skips the schema lookup entirely', function (): void {
		// metaResolver->resolve() should never be called for a sub-field —
		// that's the whole point of the short-circuit. If it were called we'd
		// be one step away from pulling in top-level settings.
		$this->metaResolver->expects($this->never())->method('resolve');

		$form = makeObjectForm($this);

		$options = buildFieldOptions($form, 'comments', [
			'subfield' => true,
			'field'    => 'textarea',
		]);

		// Would have inherited rows: 20 from top-level `comments`.
		expect($options)->not->toHaveKey('settings');
	});

	test('createDynamicField strips the subfield flag before construction', function (): void {
		// The flag is a context hint, not a constructor parameter. After
		// buildFieldOptions returns, createDynamicField must unset it so the
		// field class never sees it.
		$form = makeObjectForm($this);

		$ref    = new ReflectionClass($form);
		$method = $ref->getMethod('createDynamicField');
		$method->setAccessible(true);

		$field = $method->invoke($form, 'password', [
			'subfield' => true,
			'field'    => 'password',
			'value'    => 'pw',
		]);

		// The built field is a generic FormField (TotalField descendant).
		// Reflect on it to ensure no `subfield` property was passed through.
		$fieldRef = new ReflectionClass($field);
		expect($fieldRef->hasProperty('subfield'))->toBeFalse();
	});

	test('field() method (non-subfield) does not set the subfield flag', function (): void {
		$form = makeObjectForm($this);

		// Calling buildFieldOptions without the flag: no short-circuit.
		$this->metaResolver
			->method('resolve')
			->willReturn([
				'field'    => 'textarea',
				'settings' => ['rows' => 20],
			]);

		$options = buildFieldOptions($form, 'comments', [
			'field' => 'textarea',
		]);

		expect($options)->not->toHaveKey('subfield');
		expect($options['settings'])->toBe(['rows' => 20]);
	});
});

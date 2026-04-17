<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\DeckItemForm;
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
 * Covers DeckItemForm's preset inheritance. DeckItemForm is used when a deck
 * item is edited in its own dedicated form (distinct code path from inline
 * DeckItem rendering). Its `deckFieldDefaults` and `fieldAttributeSettings`
 * must run schema settings through the preset pipeline so named presets like
 * `st-simple` and type-default presets reach the rendered field.
 */
describe('DeckItemForm preset resolution', function (): void {
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

		$this->collectionData         = new CollectionData();
		$this->collectionData->id     = 'blog-pro';
		$this->collectionData->schema = 'blog-pro';

		// Deck schema — the one swapped in by DeckItemForm::init() via detectDeckref().
		$this->deckSchema             = new SchemaData();
		$this->deckSchema->id         = 'comment';
		$this->deckSchema->properties = [
			'comment' => [
				'field'    => 'styledtext',
				'label'    => 'Comment',
				'settings' => ['preset' => 'st-simple'],
			],
			'title' => [
				'field' => 'text',
				'label' => 'Title',
			],
		];
		$this->deckSchema->required = [];

		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->deckSchema);
	});

	function buildDeckItemForm(object $ctx): DeckItemForm
	{
		$form = new DeckItemForm(
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
			collection              : 'blog-pro',
			id                      : 'post-1',
			property                : 'comments',
			itemId                  : 'c-1',
		);

		// Force the swapped-in deck schema and deckref so we can test in isolation.
		$ref = new ReflectionClass($form);
		$ref->getProperty('schemaData')->setValue($form, $ctx->deckSchema);
		$ref->getProperty('deckref')->setValue($form, 'https://www.totalcms.co/schemas/deck/comment.json');

		return $form;
	}

	function callDeckFieldDefaults(DeckItemForm $form, string $property): array
	{
		$ref    = new ReflectionClass($form);
		$method = $ref->getMethod('deckFieldDefaults');
		$method->setAccessible(true);

		return $method->invoke($form, $property);
	}

	function callFieldAttributeSettings(DeckItemForm $form, string $property): array
	{
		$ref    = new ReflectionClass($form);
		$method = $ref->getMethod('fieldAttributeSettings');
		$method->setAccessible(true);

		return $method->invoke($form, $property);
	}

	test('deckFieldDefaults resolves a named preset on the schema settings', function (): void {
		$this->metaResolver
			->expects($this->atLeastOnce())
			->method('resolvePreset')
			->with(['preset' => 'st-simple'])
			->willReturn(['height' => 300, 'buttons' => ['bold', 'italic']]);

		$form     = buildDeckItemForm($this);
		$defaults = callDeckFieldDefaults($form, 'comment');

		expect($defaults['settings'])->toBe(['height' => 300, 'buttons' => ['bold', 'italic']]);
		expect($defaults['field'])->toBe('styledtext');
	});

	test('deckFieldDefaults falls back to type-default preset when settings are empty', function (): void {
		$this->metaResolver->method('resolvePreset')->willReturn([]);
		$this->metaResolver
			->expects($this->atLeastOnce())
			->method('resolveTypePreset')
			->with('text')
			->willReturn(['maxlength' => 120]);

		$form     = buildDeckItemForm($this);
		$defaults = callDeckFieldDefaults($form, 'title');

		expect($defaults['settings'])->toBe(['maxlength' => 120]);
	});

	test('fieldAttributeSettings extracts HTML attrs from preset-resolved settings', function (): void {
		$this->metaResolver
			->method('resolvePreset')
			->willReturn(['rows' => 6, 'maxlength' => 500, 'height' => 300]);

		$form  = buildDeckItemForm($this);
		$attrs = callFieldAttributeSettings($form, 'comment');

		// Only keys from TotalForm::ATTRIBUTE_SETTINGS are kept (rows, maxlength).
		// `height` isn't a form-field HTML attribute so it's filtered out.
		expect($attrs)->toHaveKey('rows');
		expect($attrs['rows'])->toBe(6);
		expect($attrs)->toHaveKey('maxlength');
		expect($attrs['maxlength'])->toBe(500);
		expect($attrs)->not->toHaveKey('height');
	});
});

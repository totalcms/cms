<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * `propertyOptions: pageCollections` feeds the Site Builder "Pages Collection"
 * setting. Only collections whose schema IS `builder-page`, or inherits from
 * it, may appear — a collection with any other schema offered here once let an
 * operator point the page router (and the builder-page migrations) at a
 * 5,000-object stamp catalogue.
 */
function buildPageCollectionsForm(array $collections, array $rawSchemas): TotalForm
{
	$lister = test()->createMock(CollectionLister::class);
	$lister->method('listAllCollections')->willReturn($collections);

	$fetcher = test()->createMock(SchemaFetcher::class);
	$fetcher->method('fetchRawSchema')->willReturnCallback(function (string $id) use ($rawSchemas): SchemaData {
		if (!isset($rawSchemas[$id])) {
			throw new RuntimeException("Schema not found: {$id}");
		}

		return $rawSchemas[$id];
	});

	$form = (new ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
	(new ReflectionProperty(TotalForm::class, 'collectionLister'))->setValue($form, $lister);
	(new ReflectionProperty(TotalForm::class, 'schemaFetcher'))->setValue($form, $fetcher);

	return $form;
}

function pageCollection(string $id, string $schema, string $name = ''): CollectionData
{
	$collection         = new CollectionData();
	$collection->id     = $id;
	$collection->schema = $schema;
	$collection->name   = $name;

	return $collection;
}

function rawSchema(array $inheritFrom = []): SchemaData
{
	$schema              = new SchemaData();
	$schema->inheritFrom = $inheritFrom;

	return $schema;
}

describe('TotalForm::pageCollectionOptions', function (): void {
	test('offers only collections whose schema is builder-page or inherits from it', function (): void {
		$form = buildPageCollectionsForm(
			[
				pageCollection('builder-pages', 'builder-page', 'Pages'),
				pageCollection('landing', 'landing-page', 'Landing Pages'),
				pageCollection('admiral', 'stamp', 'Admiral Stamps'),
				pageCollection('blog', 'blog'),
			],
			[
				'builder-page' => rawSchema(),
				'landing-page' => rawSchema(['builder-page']),
				'stamp'        => rawSchema(),
				'blog'         => rawSchema(),
			],
		);

		expect($form->pageCollectionOptions())->toBe([
			['value' => 'builder-pages', 'label' => 'Pages'],
			['value' => 'landing', 'label' => 'Landing Pages'],
		]);
	});

	test('falls back to the id as label and skips collections whose schema cannot be read', function (): void {
		$form = buildPageCollectionsForm(
			[
				pageCollection('pages2', 'builder-page'),
				pageCollection('orphan', 'missing-schema'),
			],
			['builder-page' => rawSchema()],
		);

		expect($form->pageCollectionOptions())->toBe([
			['value' => 'pages2', 'label' => 'pages2'],
		]);
	});

	test('returns an empty list when nothing qualifies', function (): void {
		$form = buildPageCollectionsForm(
			[pageCollection('admiral', 'stamp')],
			['stamp' => rawSchema()],
		);

		expect($form->pageCollectionOptions())->toBe([]);
	});
});

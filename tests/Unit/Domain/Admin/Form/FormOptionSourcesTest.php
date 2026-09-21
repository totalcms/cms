<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\Form\FormOptionSources;
use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Support\Config;

/**
 * The option lists a form hands its fields, as their own unit: every list
 * is a pure function of the shared services plus the arguments the caller
 * names. No form state — a form passes its own collection in where the old
 * TotalForm methods used to read it off `$this`.
 */
function collection(string $id, string $name = '', string $schema = ''): CollectionData
{
	$c         = new CollectionData();
	$c->id     = $id;
	$c->name   = $name;
	$c->schema = $schema;

	return $c;
}

describe('collections', function (): void {
	beforeEach(function (): void {
		$lister = $this->createMock(CollectionLister::class);
		$lister->method('listAllCollections')->willReturn([
			collection('blog', 'Blog', 'blog'),
			collection('pages', '', 'builder-page'),
			collection('landings', 'Landings', 'landing'),
		]);
		$lister->method('listCategories')->willReturn(['Content', 'Site']);

		$landing              = new SchemaData();
		$landing->inheritFrom = ['builder-page'];
		$fetcher              = $this->createMock(SchemaFetcher::class);
		$fetcher->method('fetchRawSchema')->willReturnCallback(fn (string $id): SchemaData => match ($id) {
			'landing' => $landing,
			default   => throw new RuntimeException("no schema $id"),
		});

		$this->sources = new FormOptionSources(formServices(['collectionLister' => $lister, 'schemaFetcher' => $fetcher]));
	});

	test('collectionIdList is every id', function (): void {
		expect($this->sources->collectionIdList())->toBe(['blog', 'pages', 'landings']);
	});

	test('collectionIdListWithLabels labels by name and falls back to the id', function (): void {
		expect($this->sources->collectionIdListWithLabels())->toBe([
			['value' => 'blog', 'label' => 'Blog'],
			['value' => 'pages', 'label' => 'pages'],
			['value' => 'landings', 'label' => 'Landings'],
		]);
	});

	test('collectionsUsingSchema matches the schema itself and schemas that inherit from it', function (): void {
		expect(array_column($this->sources->collectionsUsingSchema('builder-page'), 'value'))->toBe(['pages', 'landings']);
	});

	test('a schema that cannot be fetched never matches', function (): void {
		expect(array_column($this->sources->collectionsUsingSchema('blog'), 'value'))->toBe(['blog']);
	});

	test('pageCollectionOptions is collectionsUsingSchema for builder-page', function (): void {
		expect($this->sources->pageCollectionOptions())->toBe($this->sources->collectionsUsingSchema('builder-page'));
	});

	test('categoryListForCollections comes straight from the lister', function (): void {
		expect($this->sources->categoryListForCollections())->toBe(['Content', 'Site']);
	});

	test('collectionAndViewOptions sorts each group by label and drops an empty views group', function (): void {
		expect($this->sources->collectionAndViewOptions())->toBe([
			'Collections' => [
				['value' => 'blog', 'label' => 'Blog'],
				['value' => 'landings', 'label' => 'Landings'],
				['value' => 'pages', 'label' => 'pages'],
			],
		]);
	});
});

describe('views', function (): void {
	test('viewIdList and viewIdListWithLabels read the lister and skip malformed rows', function (): void {
		$lister = $this->createMock(DataViewLister::class);
		$lister->method('listViews')->willReturn([
			['id' => 'sales', 'name' => 'Sales Summary'],
			['id' => 'top'],
			['name' => 'no id'],
			'not an array',
		]);
		$sources = new FormOptionSources(formServices(['dataViewLister' => $lister]));

		expect($sources->viewIdList())->toBe(['sales', 'top'])
			->and($sources->viewIdListWithLabels())->toBe([
				['value' => 'sales', 'label' => 'Sales Summary'],
				['value' => 'top', 'label' => 'top'],
			]);
	});

	test('without a lister the view lists are empty', function (): void {
		$sources = new FormOptionSources(formServices());

		expect($sources->viewIdList())->toBe([])
			->and($sources->viewIdListWithLabels())->toBe([]);
	});
});

describe('builder and admin lists', function (): void {
	test('layouts, pages, middleware and nav items come from their registries', function (): void {
		$templates = $this->createMock(TemplateLister::class);
		$templates->method('listBuilderTemplates')->willReturnCallback(fn (string $folder): array => [$folder . '-one']);
		$middleware = $this->createMock(PageMiddlewareRegistry::class);
		$middleware->method('availableNames')->willReturn(['protect']);
		$nav = $this->createMock(AdminNavRegistry::class);
		$nav->method('options')->willReturn([['value' => 'dash', 'label' => 'Dashboard']]);

		$sources = new FormOptionSources(formServices([
			'templateLister'         => $templates,
			'pageMiddlewareRegistry' => $middleware,
			'navRegistry'            => $nav,
		]));

		expect($sources->layoutListForBuilder())->toBe(['layouts-one'])
			->and($sources->pageListForBuilder())->toBe(['pages-one'])
			->and($sources->pageMiddlewareList())->toBe(['protect'])
			->and($sources->adminNavItemList())->toBe([['value' => 'dash', 'label' => 'Dashboard']]);
	});

	test('every registry is optional', function (): void {
		$sources = new FormOptionSources(formServices());

		expect($sources->layoutListForBuilder())->toBe([])
			->and($sources->pageListForBuilder())->toBe([])
			->and($sources->pageMiddlewareList())->toBe([])
			->and($sources->adminNavItemList())->toBe([]);
	});
});

describe('objects and properties', function (): void {
	beforeEach(function (): void {
		$index  = new IndexData([
			['id' => 'a', 'title' => 'A', 'tag' => ['x', 'y'], 'author' => 'Ann'],
			['id' => 'b', 'title' => 'B', 'tag' => ['y', ''], 'author' => ''],
		]);
		$reader = $this->createMock(IndexReader::class);
		$reader->method('fetchIndex')->with('posts')->willReturn($index);
		$filter = $this->createMock(IndexFilter::class);
		$filter->method('fetchFilteredIndex')->with('posts', ['author' => 'Ann'])->willReturn([['id' => 'a', 'title' => 'A', 'author' => 'Ann']]);

		$schema             = new SchemaData();
		$schema->properties = ['id' => [], 'title' => []];
		$fetcher            = $this->createMock(SchemaFetcher::class);
		$fetcher->method('fetchSchemaForCollection')->willReturnCallback(fn (string $c): SchemaData => $c === 'posts' ? $schema : throw new RuntimeException('none'));
		$fetcher->method('fetchSchema')->willReturnCallback(fn (string $s): SchemaData => $s === 'post' ? $schema : throw new RuntimeException('none'));

		$this->sources = new FormOptionSources(formServices([
			'collectionReader' => $reader,
			'indexFilter'      => $filter,
			'schemaFetcher'    => $fetcher,
		]));
	});

	test('propertyListForCollection flattens, dedupes and drops empties', function (): void {
		expect(array_values($this->sources->propertyListForCollection('tag', 'posts')))->toBe(['x', 'y']);
	});

	test('propertiesForCollection picks the asked-for properties from every object', function (): void {
		expect($this->sources->propertiesForCollection(['id', 'title'], 'posts'))->toBe([
			['id' => 'a', 'title' => 'A'],
			['id' => 'b', 'title' => 'B'],
		]);
	});

	test('propertiesForCollection goes through the index filter when filters are given', function (): void {
		expect($this->sources->propertiesForCollection(['id'], 'posts', ['author' => 'Ann']))->toBe([['id' => 'a']]);
	});

	test('schemaPropertyKeys resolves by collection, then by schema name, else empty', function (): void {
		expect($this->sources->schemaPropertyKeys('posts'))->toBe(['id', 'title'])
			->and($this->sources->schemaPropertyKeys('', 'post'))->toBe(['id', 'title'])
			->and($this->sources->schemaPropertyKeys('nope'))->toBe([])
			->and($this->sources->schemaPropertyKeys())->toBe([]);
	});

	test('mediaTagsForCollection extracts tags from the index', function (): void {
		$index  = new IndexData([
			['id' => 'a', 'photo' => ['tags' => ['sky', 'sea']]],
			['id' => 'b', 'photo' => ['tags' => ['sea', '']]],
		]);
		$reader = $this->createMock(IndexReader::class);
		$reader->method('fetchIndex')->with('photos')->willReturn($index);
		$sources = new FormOptionSources(formServices(['collectionReader' => $reader]));

		expect($sources->mediaTagsForCollection('photo', 'image', 'photos'))->toBe(['sky', 'sea']);
	});
});

describe('static and config-backed lists', function (): void {
	test('accessGroupOptionsForField offers each group id as both value and label', function (): void {
		$group  = new AccessGroupData(['id' => 'editors']);
		$lister = $this->createMock(AccessGroupLister::class);
		$lister->method('listAll')->willReturn([$group]);

		expect((new FormOptionSources(formServices(['accessGroupLister' => $lister])))->accessGroupOptionsForField())
			->toBe([['value' => 'editors', 'label' => 'editors']]);
	});

	test('locales come from config, the locale and event catalogs from their registries', function (): void {
		$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->i18n = ['default' => 'de', 'available' => ['de', 'en_US']];
		$sources      = new FormOptionSources(formServices(['config' => $config]));

		expect($sources->getDefaultLocale())->toBe('de')
			->and($sources->getLocales())->toBe(['de', 'en_US'])
			->and(array_column($sources->localeList(), 'value'))->toContain('en_US')
			->and(array_column($sources->eventsList(), 'value'))->toContain('object.created');
	});

	test('podcastCategoryOptions groups Apple\'s taxonomy under each parent', function (): void {
		$groups = FormOptionSources::podcastCategoryOptions();

		expect($groups)->toHaveKey('Arts')
			->and($groups['Arts'][0])->toBe(['value' => 'Arts', 'label' => 'Arts'])
			->and(array_column($groups['Arts'], 'value'))->toContain('Arts > Books');
	});
});

<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * The option lists a form hands its fields: collections, categories,
 * schema-filtered collections, views, builder pages and layouts, locales,
 * access groups. Fields reach them through `propertyOptions` sources.
 *
 * Pinned directly, over real test data through the real factory, because the
 * refactor moves them out of TotalForm into FormOptionSources and the only
 * other coverage is incidental — whichever option list a rendered form
 * happened to include.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	foreach (['blog', 'image', 'text'] as $id) {
		$c->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => ucfirst($id), 'schema' => $id]);
	}
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'landing',
		'type'        => 'object',
		'inheritFrom' => ['builder-page'],
		'properties'  => ['hero' => ['type' => 'string', 'field' => 'text']],
	]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'pages', 'name' => 'Site Pages', 'schema' => 'builder-page']);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'landings', 'name' => 'Landing Pages', 'schema' => 'landing']);

	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'golden-post', 'title' => 'Golden Post', 'author' => 'Golden Author']);

	$this->form = $c->get(TotalFormFactory::class)->builder('blog');
});

function providerForm(): TotalForm
{
	return test()->form;
}

it('lists every collection id', function (): void {
	expect(providerForm()->collectionIdList())->toContain('blog', 'image', 'pages', 'landings');
});

it('labels a collection by its name', function (): void {
	$byValue = array_column(providerForm()->collectionIdListWithLabels(), 'label', 'value');

	expect($byValue['pages'])->toBe('Site Pages')
		->and($byValue['landings'])->toBe('Landing Pages');
});

it('offers the collections whose schema is or extends the asked-for one', function (): void {
	$values = array_column(providerForm()->collectionsUsingSchema('builder-page'), 'value');

	expect($values)->toContain('pages', 'landings')
		->and($values)->not->toContain('blog');
});

it('offers page collections through the builder-page alias', function (): void {
	expect(array_column(providerForm()->pageCollectionOptions(), 'value'))->toBe(array_column(providerForm()->collectionsUsingSchema('builder-page'), 'value'));
});

it('groups collections and views, sorted by label, and drops an empty group', function (): void {
	$groups = providerForm()->collectionAndViewOptions();

	$labels = array_column($groups['Collections'], 'label');
	$sorted = $labels;
	usort($sorted, strnatcasecmp(...));

	expect($labels)->toBe($sorted)
		->and($groups)->not->toHaveKey('Data Views');
});

it('lists the schema property keys for a collection', function (): void {
	expect(providerForm()->schemaPropertyKeys('landings'))->toContain('hero')
		->and(providerForm()->schemaPropertyKeys())->toContain('title');
});

it('returns no keys for a collection it cannot resolve', function (): void {
	expect(providerForm()->schemaPropertyKeys('nope'))->toBe([]);
});

it('plucks the distinct values of one property across a collection', function (): void {
	expect(providerForm()->propertyListForCollection('author', 'blog'))->toContain('Golden Author')
		->and(providerForm()->propertyListForCollection('author'))->toContain('Golden Author');
});

it('offers every registered locale, including the configured default', function (): void {
	$default = providerForm()->getDefaultLocale();

	expect($default)->toBe('en_US')
		->and(providerForm()->getLocales())->toBeArray()
		->and(array_column(providerForm()->localeList(), 'value'))->toContain($default);
});

it('offers the core events by name', function (): void {
	expect(array_column(providerForm()->eventsList(), 'value'))->toContain('object.created', 'object.deleted');
});

it('lists the collection categories', function (): void {
	expect(providerForm()->categoryListForCollections())->toBeArray();
});

it('offers every access group by id', function (): void {
	foreach (providerForm()->accessGroupOptionsForField() as $option) {
		expect($option)->toHaveKeys(['value', 'label'])
			->and($option['label'])->toBe($option['value']);
	}
});

it('lists builder layouts and pages only once a template lister is attached', function (): void {
	// The factory attaches one; both lists are arrays of template names.
	expect(providerForm()->layoutListForBuilder())->toBeArray()
		->and(providerForm()->pageListForBuilder())->toBeArray()
		->and(providerForm()->pageMiddlewareList())->toBeArray();
});

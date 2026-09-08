<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Behaviour of the `cms.collection.*` helpers that had no test: listing and
 * grouping, counts, property values, and the object-URL family (plain,
 * pretty, templated, canonical, empty-segment detection, template-field
 * validation) — through the public API against the real container.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'article', 'type' => 'object', 'properties' => [
		'id'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'    => ['type' => 'string', 'field' => 'text'],
		'category' => ['type' => 'string', 'field' => 'text'],
		'tags'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/list.json', 'field' => 'list'],
		'note'     => ['type' => 'string', 'field' => 'text'],
	], 'required' => ['id', 'title'], 'index' => ['id', 'title', 'category', 'tags']]);
	$collections = $c->get(CollectionSaver::class);
	$collections->saveCollection(['id' => 'plain', 'name' => 'Plain', 'schema' => 'article', 'url' => '/plain.php', 'prettyUrl' => false, 'category' => 'Writing']);
	$collections->saveCollection(['id' => 'pretty', 'name' => 'Pretty', 'schema' => 'article', 'url' => '/pretty/', 'prettyUrl' => true, 'category' => 'Writing']);
	$collections->saveCollection(['id' => 'templated', 'name' => 'Templated', 'schema' => 'article', 'url' => '/reads/{{ category }}/{{ id }}', 'prettyUrl' => true, 'category' => 'Archive']);
	$collections->saveCollection(['id' => 'loose', 'name' => 'Loose', 'schema' => 'article', 'url' => '/loose/{{ note }}', 'prettyUrl' => false]);
	$saver = $c->get(ObjectSaver::class);
	foreach (['plain', 'pretty', 'templated', 'loose'] as $id) {
		$saver->saveObject($id, ['id' => 'one', 'title' => 'One', 'category' => 'news', 'tags' => ['a', 'b']]);
		$saver->saveObject($id, ['id' => 'two', 'title' => 'Two', 'category' => 'sport', 'tags' => ['b', 'c']]);
	}
	$saver->saveObject('templated', ['id' => 'three', 'title' => 'Three', 'category' => '', 'tags' => []]);

	$this->collection = $c->get(CollectionTwigAdapter::class);
	$this->config     = $c->get(Config::class);
});

test('list() returns every accessible collection and byCategory() groups them with "Collections" last', function (): void {
	$ids = array_map(fn ($c) => $c->id, $this->collection->list());
	expect($ids)->toContain('plain', 'pretty', 'templated', 'loose');

	$grouped = $this->collection->byCategory();
	$keys    = array_keys($grouped);
	expect($keys)->toContain('Archive', 'Writing', 'Collections')
		->and(end($keys))->toBe('Collections')
		->and(array_search('Archive', $keys, true))->toBeLessThan(array_search('Writing', $keys, true))
		->and(array_map(fn ($c) => $c->id, $grouped['Writing']))->toBe(['plain', 'pretty'])
		->and(array_map(fn ($c) => $c->id, $grouped['Collections']))->toContain('loose');
});

test('objectCount() reads the collection metadata and is zero for an unknown collection', function (): void {
	expect($this->collection->objectCount('templated'))->toBe(3)
		->and($this->collection->objectCount('plain'))->toBe(2)
		->and($this->collection->objectCount('nope'))->toBe(0);
});

test('property() lists the unique, flattened values of an indexed property', function (): void {
	expect($this->collection->property('plain', 'category'))->toEqualCanonicalizing(['news', 'sport'])
		->and($this->collection->property('plain', 'tags'))->toEqualCanonicalizing(['a', 'b', 'c']);
});

test('objectUrl() builds a query-string URL for a plain collection and a folder URL for a pretty one', function (): void {
	expect($this->collection->objectUrl('plain', 'one'))->toBe('/plain.php?id=one')
		->and($this->collection->objectUrl('pretty', 'one'))->toBe('/pretty/one')
		->and($this->collection->objectUrl('pretty', ['id' => 'two']))->toBe('/pretty/two')
		->and($this->collection->objectUrl('nope', 'one'))->toBe('');
});

test('objectUrl() renders a templated URL from the object, fetching it when only an id is given', function (): void {
	expect($this->collection->objectUrl('templated', 'one'))->toBe('/reads/news/one')
		->and($this->collection->objectUrl('templated', ['id' => 'x', 'category' => 'tech']))->toBe('/reads/tech/x')
		// Templated URLs are implicitly pretty, so the flag does not matter…
		->and($this->collection->objectUrl('loose', ['id' => 'one', 'note' => 'n']))->toBe('/loose/n/one');
});

test('canonicalObjectUrl() makes the object URL absolute on the configured domain', function (): void {
	$this->config->domain = 'example.test';

	expect($this->collection->canonicalObjectUrl('pretty', 'one'))->toBe('https://example.test/pretty/one')
		->and($this->collection->canonicalObjectUrl('nope', 'one'))->toBe('');
});

test('hasTemplateUrl(), urlTemplateFields() and objectUrlHasEmptySegments() describe templated URLs', function (): void {
	expect($this->collection->hasTemplateUrl('templated'))->toBeTrue()
		->and($this->collection->hasTemplateUrl('pretty'))->toBeFalse()
		->and($this->collection->hasTemplateUrl('nope'))->toBeFalse()
		->and($this->collection->urlTemplateFields('templated'))->toBe(['category', 'id'])
		->and($this->collection->urlTemplateFields('pretty'))->toBe([]);

	// `three` has an empty category, which leaves a hole in its URL.
	expect($this->collection->objectUrlHasEmptySegments('templated', 'one'))->toBeFalse()
		->and($this->collection->objectUrlHasEmptySegments('templated', 'three'))->toBeTrue()
		->and($this->collection->objectUrlHasEmptySegments('pretty', 'one'))->toBeFalse();
});

test('validateUrlTemplateFields() flags fields that are not indexed or not required, and a disabled pretty URL', function (): void {
	// `note` is in the URL but neither indexed nor required; `category` is
	// indexed but not required; pretty URLs are off on `loose`.
	expect($this->collection->validateUrlTemplateFields('loose'))->toBe(['notIndexed' => ['note'], 'notRequired' => ['note'], 'prettyUrlDisabled' => true])
		->and($this->collection->validateUrlTemplateFields('templated'))->toBe(['notIndexed' => [], 'notRequired' => ['category'], 'prettyUrlDisabled' => false])
		// Not a template: nothing to validate.
		->and($this->collection->validateUrlTemplateFields('pretty'))->toBe(['notIndexed' => [], 'notRequired' => [], 'prettyUrlDisabled' => false])
		->and($this->collection->validateUrlTemplateFields('nope'))->toBe(['notIndexed' => [], 'notRequired' => [], 'prettyUrlDisabled' => false]);
});

<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\CollectionForm;
use TotalCMS\Domain\Admin\DeckItemForm;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * FormOptions is everything that varies per form — identity, presentation,
 * behaviour flags — as one typed object. The factory builds it from the
 * option arrays Twig and the admin pass in; a test names its arguments.
 * A key nobody declared is an error with the key in it, not a silently
 * ignored option or a PHP "unknown named parameter" from deep in a spread.
 */
it('carries the per-form options with the same defaults the constructor had', function (): void {
	$options = new FormOptions(api: '/api');

	expect($options->api)->toBe('/api')
		->and($options->collection)->toBe('')
		->and($options->method)->toBe('POST')
		->and($options->useFormGrid)->toBeTrue()
		->and($options->fieldIcons)->toBeTrue()
		->and($options->addOnly)->toBeFalse()
		->and($options->register)->toBeFalse()
		->and($options->newActions)->toBe([]);
});

it('builds from the option arrays the factory assembles', function (): void {
	$options = FormOptions::fromArray(['api' => '/api', 'collection' => 'blog', 'id' => 'hello', 'autosave' => true]);

	expect($options->collection)->toBe('blog')
		->and($options->id)->toBe('hello')
		->and($options->autosave)->toBeTrue();
});

it('names an option nobody declared', function (): void {
	expect(fn () => FormOptions::fromArray(['api' => '/api', 'colection' => 'blog']))
		->toThrow(InvalidArgumentException::class, "colection");
});

it('copies itself with changes', function (): void {
	$options = new FormOptions(api: '/api', collection: 'blog', class: 'wide');
	$changed = $options->with(newActions: [['action' => 'redirect-object', 'link' => '?id=']], data: []);

	expect($changed)->not->toBe($options)
		->and($changed->collection)->toBe('blog')
		->and($changed->class)->toBe('wide')
		->and($changed->newActions)->toBe([['action' => 'redirect-object', 'link' => '?id=']])
		->and($options->newActions)->toBe([]);
});

it('is what a form is built from', function (): void {
	$form = new TotalForm(formServices(), new FormOptions(api: '/api', collection: 'things', id: 'one', addOnly: true));

	expect($form->collection)->toBe('things')
		->and($form->api)->toBe('/api')
		// addOnly forms never edit: the id is dropped at init.
		->and($form->id)->toBe('');
});

it('keeps the subclass default: a collection form redirects to the new record', function (): void {
	$schemaFetcher = $this->createMock(SchemaFetcher::class);
	$schemaFetcher->method('fetchSchema')->willReturn(new SchemaData());

	$form = new CollectionForm(formServices(['schemaFetcher' => $schemaFetcher]), new FormOptions(api: '/api'));

	expect((new ReflectionProperty(TotalForm::class, 'newActions'))->getValue($form))
		->toBe([['action' => 'redirect-object', 'link' => '?id=']]);
});

it('keeps the subclass default: a deck item form is a deck form', function (): void {
	$objects = $this->createMock(ObjectFetcher::class);
	$objects->method('existsObject')->willReturn(false);
	$collection         = new CollectionData();
	$collection->id     = 'widgets';
	$collection->schema = 'widgets';
	$collections        = $this->createMock(CollectionFetcher::class);
	$collections->method('fetchCollection')->willReturn($collection);
	$schemaFetcher = $this->createMock(SchemaFetcher::class);
	$schemaFetcher->method('fetchSchema')->willReturn(new SchemaData());

	$form = new DeckItemForm(
		formServices(['objectFetcher' => $objects, 'collectionFetcher' => $collections, 'schemaFetcher' => $schemaFetcher]),
		new FormOptions(api: '/api', collection: 'widgets'),
		property: 'mydeck',
	);

	expect((new ReflectionProperty(TotalForm::class, 'formType'))->getValue($form))->toBe('deck');
});

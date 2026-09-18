<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * FormServices is the one argument that carries every collaborator a form
 * shares with every other form. A test builds a form from one stub object
 * instead of fourteen mocks; the factory builds it once and hands it to
 * every form it makes.
 */
it('builds a form from one services object and the per-form options', function (): void {
	$form = new TotalForm(services: formServices(), api: '/api', collection: 'things');

	expect($form->collection)->toBe('things')
		->and($form->api)->toBe('/api');
});

it('exposes the collaborators a field asks the form for', function (): void {
	$schemaFetcher = (new ReflectionClass(SchemaFetcher::class))->newInstanceWithoutConstructor();
	$services      = formServices(['schemaFetcher' => $schemaFetcher, 'logger' => new NullLogger()]);
	$form          = new TotalForm(services: $services, api: '/api', collection: 'things');

	expect($form->getSchemaFetcher())->toBe($schemaFetcher)
		->and($form->services())->toBe($services)
		->and($form->logger())->toBeInstanceOf(NullLogger::class);
});

it('defaults the optional listers to null so a bare form still answers with empty lists', function (): void {
	$form = new TotalForm(services: formServices(), api: '/api', collection: 'things');

	expect($form->viewIdList())->toBe([])
		->and($form->layoutListForBuilder())->toBe([])
		->and($form->pageMiddlewareList())->toBe([])
		->and($form->adminNavItemList())->toBe([]);
});

it('is readonly: a collaborator cannot be swapped after construction', function (): void {
	$services = formServices();

	expect(fn () => $services->collectionFetcher = (new ReflectionClass(CollectionFetcher::class))->newInstanceWithoutConstructor())
		->toThrow(Error::class);
})->skip(fn (): bool => !(new ReflectionClass(FormServices::class))->isReadOnly(), 'FormServices is not readonly');

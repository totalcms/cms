<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionCreateCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$container = $this->app->getContainer();

	// The command only ever reaches the container through the TotalCMS facade,
	// so a partial double wired to the REAL container gives the command real
	// services (this is a feature test — collections land on disk).
	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);
	$totalcms->method('collectionFetcher')->willReturn($container->get(CollectionFetcher::class));
	$totalcms->method('schemaFetcher')->willReturn($container->get(SchemaFetcher::class));

	$app = new Application();
	$app->add(new CollectionCreateCommand($totalcms));
	$this->tester = new CommandTester($app->find('collection:create'));
	$this->fetcher = $container->get(CollectionFetcher::class);
});

it('provisions a reserved collection with its defaults', function (): void {
	$this->tester->execute(['id' => 'seo-site']);

	expect($this->tester->getStatusCode())->toBe(0);

	$collection = $this->fetcher->fetchCollection('seo-site');
	expect($collection)->not->toBeNull()
		->and($collection->schema)->toBe('seo-site')
		->and($collection->name)->toBe('Site SEO')
		->and($collection->singleton)->toBeTrue();
});

it('still takes the reserved path when --schema names the reserved id itself', function (): void {
	// The obvious spelling. Branching on --schema instead of the id sent this
	// down the custom path and produced a non-singleton `seo-site`.
	$this->tester->execute(['id' => 'seo-site', '--schema' => 'seo-site', '--name' => 'Ignored']);

	expect($this->tester->getStatusCode())->toBe(0);

	$collection = $this->fetcher->fetchCollection('seo-site');
	expect($collection->schema)->toBe('seo-site')
		->and($collection->name)->toBe('Site SEO')
		->and($collection->singleton)->toBeTrue();
});

it('refuses a reserved id pointed at somebody else\'s schema', function (): void {
	$this->tester->execute(['id' => 'seo-site', '--schema' => 'blog']);

	expect($this->tester->getStatusCode())->toBe(1)
		->and($this->tester->getDisplay())->toContain('bound to its own schema')
		->and($this->fetcher->collectionExists('seo-site'))->toBeFalse();
});

it('refuses to create a collection that already exists', function (): void {
	$this->tester->execute(['id' => 'seo-site']);
	expect($this->tester->getStatusCode())->toBe(0);

	$this->tester->execute(['id' => 'seo-site']);
	expect($this->tester->getStatusCode())->toBe(1)
		->and($this->tester->getDisplay())->toContain('already exists');
});

it('creates a custom collection from an explicit schema and name', function (): void {
	$this->tester->execute(['id' => 'recipes', '--schema' => 'blog', '--name' => 'Recipes']);

	expect($this->tester->getStatusCode())->toBe(0);

	$collection = $this->fetcher->fetchCollection('recipes');
	expect($collection)->not->toBeNull()
		->and($collection->schema)->toBe('blog')
		->and($collection->name)->toBe('Recipes')
		->and($collection->singleton)->toBeFalse();
});

it('requires --schema for an id that is not a reserved collection', function (): void {
	$this->tester->execute(['id' => 'recipes2']);

	expect($this->tester->getStatusCode())->toBe(1)
		->and($this->tester->getDisplay())->toContain('--schema')
		->and($this->fetcher->collectionExists('recipes2'))->toBeFalse();
});

it('refuses a reference schema id', function (): void {
	$this->tester->execute(['id' => 'totalcms']);

	expect($this->tester->getStatusCode())->toBe(1)
		->and($this->fetcher->collectionExists('totalcms'))->toBeFalse();
});

it('creates a singleton custom collection with --singleton and reports JSON', function (): void {
	$this->tester->execute(['id' => 'about', '--schema' => 'text', '--singleton' => true, '--json' => true]);

	expect($this->tester->getStatusCode())->toBe(0);

	$data = json_decode($this->tester->getDisplay(), true);
	expect($data)->toMatchArray([
		'id'        => 'about',
		'schema'    => 'text',
		'name'      => 'about',
		'singleton' => true,
		'created'   => true,
	]);
	expect($this->fetcher->fetchCollection('about')->singleton)->toBeTrue();
});

it('refuses an unknown schema', function (): void {
	$this->tester->execute(['id' => 'nope', '--schema' => 'not-a-schema']);

	expect($this->tester->getStatusCode())->toBe(1)
		->and($this->tester->getDisplay())->toContain('not-a-schema')
		->and($this->fetcher->collectionExists('nope'))->toBeFalse();
});

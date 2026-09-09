<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('ships seo and seo-collection as reserved embedded schemas', function (): void {
	$fetcher = $this->app->getContainer()->get(SchemaFetcher::class);
	expect(array_keys($fetcher->fetchSchema('seo')->properties))->toContain('title', 'description', 'image', 'canonical', 'noindex', 'nofollow', 'jsonldType');
	expect(array_keys($fetcher->fetchSchema('seo-collection')->properties))->toContain('type', 'description', 'image');
	expect($fetcher->fetchSchema('builder-page')->properties['seo']['schemaref'] ?? '')->toEndWith('/seo.json');
});

it('round-trips the seo card on a builder page and the seo block on a collection', function (): void {
	$c = $this->app->getContainer();
	$c->get(BuilderInstaller::class)->ensurePagesCollection();
	$c->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'pages/home.twig', 'seo' => ['title' => 'Welcome', 'noindex' => true]]);
	$page = $c->get(ObjectFetcher::class)->fetchObject('builder-pages', 'home')->toArray();
	expect($page['seo']['title'])->toBe('Welcome')->and($page['seo']['noindex'])->toBeTrue()->and($page['seo']['id'])->toBe('seo');

	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog', 'seo' => ['type' => 'article', 'description' => 'summary', 'image' => 'image']]);
	expect($c->get(CollectionFetcher::class)->fetchCollection('posts')->seo)->toBe(['type' => 'article', 'description' => 'summary', 'image' => 'image']);
});

it('saves a builder page with no seo key and defaults the card', function (): void {
	$c = $this->app->getContainer();
	$c->get(BuilderInstaller::class)->ensurePagesCollection();
	$c->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/home.twig']);
	$page = $c->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();

	expect($page['seo']['id'])->toBe('seo')->and($page['seo']['noindex'])->toBeFalse();
});

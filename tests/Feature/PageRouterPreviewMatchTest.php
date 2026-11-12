<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;

// The router's preview entry points (Share Cards): a RouteMatch for any page
// record or collection object, drafts included, without matching a path.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	// Before boot: the Twig loader takes its paths when it is built.
	is_dir(cmsDataDir() . 'builder/pages') || mkdir(cmsDataDir() . 'builder/pages', 0755, true);
	file_put_contents(templatePath('about', 'pages'), '<h1>{{ page.title }}</h1>');
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();

	$this->c->get(BuilderInstaller::class)->ensurePagesCollection();

	$this->c->get(ObjectSaver::class)->saveObject('builder-pages', [
		'id'       => 'about',
		'title'    => 'About Us',
		'route'    => '/about',
		'template' => 'about',
		'draft'    => true,
	]);
	$this->c->get(IndexBuilder::class)->buildIndex('builder-pages');

	$this->c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$this->c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'hello', 'title' => 'Hello']);

	$this->router = $this->c->get(PageRouter::class);
});

it('matches a draft page record that path matching skips', function (): void {
	$entry = $this->c->get(IndexReader::class)->fetchIndex('builder-pages')->objects[0] ?? [];

	$match = $this->router->matchForPage(new PageData($entry));

	expect($match->template)->toBe('pages/about.twig')
		->and($match->pageData['title'] ?? '')->toBe('About Us')
		->and($match->collection)->toBeNull()
		->and($this->router->match('/about'))->toBeNull();
});

it('matches a collection object by id', function (): void {
	$blog  = $this->c->get(CollectionFetcher::class)->fetchCollection('blog');
	$match = $this->router->matchForObject($blog, 'hello');

	expect($match?->collection)->toBe('blog')
		->and($match?->template)->toBe('pages/blog.twig')
		->and($match?->params['id'] ?? '')->toBe('hello')
		->and($match?->pageData['title'] ?? '')->toBe('Hello')
		->and($this->router->matchForObject($blog, 'missing'))->toBeNull();
});

it('says whether a template exists', function (): void {
	$twig = $this->c->get(TwigEngine::class);

	expect($twig->templateExists('pages/about.twig'))->toBeTrue()
		->and($twig->templateExists('pages/nope.twig'))->toBeFalse();
});

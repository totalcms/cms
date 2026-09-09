<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$container = $this->app->getContainer();
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	// The reserved collection ships with no URL, so objects in it have no
	// address to be canonical about — give it one.
	$container->get(CollectionSaver::class)->patchCollection('blog', ['url' => '/blog/{id}']);
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'      => 'hello',
		'title'   => 'Hello & Welcome',
		'summary' => '<p>Sum <b>mary</b></p>',
		'author'  => 'Joe',
	]);
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$container->get(ObjectSaver::class)->saveObject('seo-site', [
		'id'                 => 'seo-site',
		'siteName'           => 'Bistro',
		'twitterHandle'      => 'bistro',
		'googleVerification' => 'g123',
		'defaultImage'       => ['name' => 'share.jpg', 'size' => 10],
	]);

	$this->render = fn (string $template, array $vars = []): string => $container->get(TwigEngine::class)->renderString($template, $vars);
});

it('renders the whole head for a collection object', function (): void {
	$post = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
	$html = ($this->render)("{{ cms.seo.head(post, {collection: 'blog'}) }}", ['post' => $post]);

	expect($html)->toContain('<title>Hello &amp; Welcome | Bistro</title>')
		->toContain('<meta name="description" content="Sum mary">')
		->toContain('<link rel="canonical" href="https://')
		->toContain('/hello')
		->toContain('<meta property="og:type" content="article">')
		->toContain('<meta name="twitter:site" content="@bistro">')
		->toContain('<meta name="google-site-verification" content="g123">')
		->toContain('"@type":"Article"')
		->not->toContain('<meta name="robots"');

	// Escaped exactly once: no double-escaping of the markup we emit, and the
	// `&` in the title survives as a single entity.
	expect($html)->not->toContain('&lt;')
		->not->toContain('&amp;amp;');
	expect(substr_count($html, 'Hello &amp; Welcome'))->toBeGreaterThan(0);
});

it('renders site defaults with no subject and honours noindex', function (): void {
	$html = ($this->render)('{{ cms.seo.head() }}');
	expect($html)->toContain('<title>Bistro</title>')
		->toContain('"@type":"WebSite"')
		->not->toContain('"@type":"WebPage"');

	// The uploaded default social image survives the whole chain: saved on the
	// `seo-site` record, resolved to an absolute ImageWorks URL by the loader,
	// and emitted as og:image at Open Graph's 1200×630.
	expect($html)->toContain('<meta property="og:image" content="https://')
		->toContain('/imageworks/seo-site/seo-site/defaultImage.jpg')
		->toContain('w=1200');

	$page = ['id' => 'secret', 'title' => 'Secret', 'route' => '/secret', 'template' => 'pages/x.twig', 'seo' => ['noindex' => true, 'nofollow' => true]];
	expect(($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]))
		->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('granular methods return only their slice', function (): void {
	$page = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/about.twig'];

	expect(($this->render)('{{ cms.seo.title(page) }}', ['page' => $page]))->toBe('<title>About | Bistro</title>');
	expect(($this->render)('{{ cms.seo.canonical(page) }}', ['page' => $page]))->toStartWith('<link rel="canonical"');
	expect(($this->render)('{{ cms.seo.og(page) }}', ['page' => $page]))->toContain('og:title')->not->toContain('<title>');
	expect(($this->render)('{{ cms.seo.jsonld(page) }}', ['page' => $page]))->toStartWith('<script type="application/ld+json">');
	expect(($this->render)('{{ cms.seo.meta(page) }}', ['page' => $page]))
		->toContain('<meta name="google-site-verification" content="g123">')
		->not->toContain('<title>');
});


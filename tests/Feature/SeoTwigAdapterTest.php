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
		'defaultImage'       => ['name' => 'share.jpg', 'size' => 10, 'alt' => 'Share alt'],
	]);

	$this->render = fn (string $template, array $vars = []): string => $container->get(TwigEngine::class)->renderString($template, $vars);
});

it('renders the whole head for a collection object', function (): void {
	$post = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
	$html = ($this->render)("{{ cms.seo.head(post, {collection: 'blog'}) }}", ['post' => $post]);

	expect($html)->toContain('<title>Hello &amp; Welcome | Bistro</title>')
		->toContain('<meta name="description" content="Sum mary">')
		->toContain('<link rel="canonical" href="http://totalcms.test/')
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
	expect($html)->toContain('<meta property="og:image" content="http://totalcms.test/')
		->toContain('/imageworks/seo-site/seo-site/defaultImage.jpg')
		->toContain('w=1200');

	// The alt saved on the image object travels with it into both share cards.
	expect($html)->toContain('<meta property="og:image:alt" content="Share alt">')
		->toContain('<meta name="twitter:image:alt" content="Share alt">');

	$page = ['id' => 'secret', 'title' => 'Secret', 'route' => '/secret', 'template' => 'pages/x.twig', 'seo' => ['noindex' => true, 'nofollow' => true]];
	expect(($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]))
		->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('drops the canonical link on a noindex page but keeps og:url', function (): void {
	$page = ['id' => 'secret', 'title' => 'Secret', 'route' => '/secret', 'template' => 'pages/x.twig', 'seo' => ['noindex' => true]];
	$html = ($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]);

	expect($html)->not->toContain('rel="canonical"');
	expect($html)->toContain('<meta name="robots" content="noindex">')
		->toContain('<meta property="og:url" content="http://totalcms.test/');
});

it('uses socialTitle for the share cards only', function (): void {
	$page = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/x.twig', 'seo' => ['socialTitle' => 'Share Me', 'description' => 'Card copy']];
	$html = ($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]);

	expect($html)->toContain('<meta property="og:title" content="Share Me">')
		->toContain('<meta name="twitter:title" content="Share Me">')
		->toContain('<meta name="twitter:description" content="Card copy">')
		->toContain('<title>About | Bistro</title>');

	// With no social title both share titles fall back to the raw page title,
	// and the description and image are declared for Twitter explicitly too.
	$plain = ($this->render)('{{ cms.seo.head(page) }}', ['page' => ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/x.twig']]);
	expect($plain)->toContain('<meta property="og:title" content="About">')
		->toContain('<meta name="twitter:title" content="About">')
		->toContain('<meta name="twitter:image" content="http://totalcms.test/');
});

it('takes a builder page description from the SEO card only', function (): void {
	$card = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/x.twig', 'seo' => ['description' => 'From the card']];
	expect(($this->render)('{{ cms.seo.head(page) }}', ['page' => $card]))
		->toContain('<meta name="description" content="From the card">');

	// The page schema no longer carries a top-level description — a legacy
	// value passed in from a template is not a source for the meta tag.
	$legacy = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/x.twig', 'description' => 'Legacy value'];
	expect(($this->render)('{{ cms.seo.head(page) }}', ['page' => $legacy]))
		->not->toContain('Legacy value');
});

it('merges template-supplied JSON-LD nodes into the one graph', function (): void {
	$page = ['id' => 'faq', 'title' => 'FAQ', 'route' => '/faq', 'template' => 'pages/x.twig'];
	$html = ($this->render)("{{ cms.seo.head(page, {jsonld: [{'@type': 'FAQPage', 'mainEntity': []}]}) }}", ['page' => $page]);

	expect(substr_count($html, '<script type="application/ld+json">'))->toBe(1);
	expect($html)->toContain('"FAQPage"')->toContain('"WebPage"');

	// The same nodes reach the granular method, and an entry that isn't a node
	// is dropped instead of landing in the graph as a bare string. A
	// list-shaped entry — one node wrapped in an extra pair of brackets — goes
	// the same way: it would encode as a nested JSON array, not a node.
	$only = ($this->render)("{{ cms.seo.jsonld(page, {jsonld: [{'@type': 'FAQPage'}, 'nope', [{'@type': 'Sneaky'}]]}) }}", ['page' => $page]);
	expect($only)->toContain('"FAQPage"')
		->not->toContain('nope')
		->not->toContain('Sneaky');
});

it('exposes the resolved meta values as data', function (): void {
	$page = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/x.twig'];
	$out  = ($this->render)('{% set d = cms.seo.data(page) %}{{ d.title }}|{{ d.site.name }}|{{ d.ogImage }}', ['page' => $page]);

	// The title carries the site's own separator, so match the whole render
	// rather than splitting on it.
	expect($out)->toStartWith('About | Bistro|Bistro|http://totalcms.test/')
		->toContain('/imageworks/seo-site/seo-site/defaultImage.jpg');

	$more = ($this->render)('{% set d = cms.seo.data(page) %}{{ d.noindex ? "yes" : "no" }}|{{ d.site.defaultImageAlt }}|{{ d.ogImageAlt }}', ['page' => $page]);
	expect($more)->toBe('no|Share alt|Share alt');
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


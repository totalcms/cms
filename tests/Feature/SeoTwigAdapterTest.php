<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
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
		'metaTags'           => '<meta name="google-site-verification" content="g123">' . "\n" . '<script src="/x.js"></script>',
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
		->toContain('<meta property="article:published_time" content="')
		->toContain('<meta name="twitter:site" content="@bistro">')
		// Meta Tags are printed as pasted, unescaped — a verification tag, a
		// script, whatever the operator needs in the head.
		->toContain('<meta name="google-site-verification" content="g123">' . "\n" . '<script src="/x.js"></script>')
		->toContain('"@type":"BlogPosting"')
		->not->toContain('<meta name="robots"');

	// Escaped exactly once: no double-escaping of the markup we emit, and the
	// `&` in the title survives as a single entity.
	expect($html)->not->toContain('&lt;')
		->not->toContain('&amp;amp;');
	expect(substr_count($html, 'Hello &amp; Welcome'))->toBeGreaterThan(0);
});

it('gives a page the router did not render its head through cms.builder.page()', function (): void {
	// A Stacks site: Apache serves /blog/index.php itself, and the blog
	// collection's pretty URL names the posts. Neither page reaches the page
	// router, so the layout asks it what routes the request instead.
	$container = $this->app->getContainer();
	$container->get(CollectionSaver::class)->patchCollection('blog', ['prettyUrl' => true]);
	$container->get(BuilderInstaller::class)->ensurePagesCollection();
	// The carrier record has no template: nothing renders it.
	$container->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'blog', 'title' => 'Blog', 'route' => '/blog', 'seo' => ['description' => 'All the posts']]);

	// The index page under its file spelling matches the /blog record, not
	// /blog/{id} with "index.php" for an id.
	$html = ($this->render)("{{ cms.seo.head(cms.builder.page('/blog/index.php')) }}");
	expect($html)->toContain('<title>Blog | Bistro</title>')
		->toContain('<meta name="description" content="All the posts">')
		->toContain('<link rel="canonical" href="http://totalcms.test/blog">');

	// A post page needs no record: the collection URL routes it to the
	// object, and the object comes back knowing its collection.
	$html = ($this->render)("{{ cms.seo.head(cms.builder.page('/blog/hello')) }}");
	expect($html)->toContain('<title>Hello &amp; Welcome | Bistro</title>')
		->toContain('<meta name="description" content="Sum mary">')
		->toContain('<meta property="og:type" content="article">')
		->toContain('"@type":"BlogPosting"')
		->toContain('/blog/hello">');
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
		->toContain('<script src="/x.js"></script>')
		->not->toContain('<title>');
});

it('emits no icon tags when the record has no icon', function (): void {
	$html = ($this->render)('{{ cms.seo.head() }}');

	expect($html)->not->toContain('rel="icon"')->not->toContain('apple-touch-icon')->not->toContain('theme-color')->not->toContain('rel="manifest"');
	expect(($this->render)('{{ cms.seo.icons() }}'))->toBe('');
});

it('emits the icon set, the touch icon, the SVG first and the theme color', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectUpdater::class)->updateObject('seo-site', 'seo-site', [
		'id'         => 'seo-site',
		'siteName'   => 'Bistro',
		'icon'       => ['name' => 'icon.png', 'size' => 10],
		'iconSvg'    => ['name' => 'icon.svg', 'size' => 10, 'mime' => 'image/svg+xml'],
		// Pure red survives the hex → OKLCH → hex round trip the color
		// property applies on read; a mid-range color can drift by one.
		'themeColor' => '#FF0000',
	]);
	$page = ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/about.twig'];

	$html = ($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]);

	expect($html)
		->toContain('<link rel="icon" href="http://totalcms.test/favicon.svg" type="image/svg+xml">')
		->toMatch('~<link rel="icon" href="http://totalcms\.test/imageworks/seo-site/seo-site/icon\.png[^"]*w=32[^"]*" type="image/png" sizes="32x32">~')
		->toMatch('~sizes="192x192"~')
		->toMatch('~sizes="512x512"~')
		// No Touch Icon uploaded: the 180px touch icon is cut from the Icon,
		// over the theme color so iOS does not paint the transparency black.
		->toMatch('~<link rel="apple-touch-icon" href="http://totalcms\.test/imageworks/seo-site/seo-site/icon\.png[^"]*w=140[^"]*bg=ff0000[^"]*border=20%2Cff0000%2Cexpand[^"]*" sizes="180x180">~')
		->toContain('<meta name="theme-color" content="#ff0000">');

	// The SVG is listed before the PNGs so a browser that can use it does.
	expect(strpos($html, 'favicon.svg'))->toBeLessThan((int)strpos($html, 'sizes="32x32"'));

	// The slice prints only the icon tags.
	$slice = ($this->render)('{{ cms.seo.icons(page) }}', ['page' => $page]);
	expect($slice)->toStartWith('<link rel="icon"')->toContain('theme-color')->not->toContain('<title>')->not->toContain('og:');
});

it('links a web app manifest when a builder page owns /manifest.webmanifest', function (): void {
	// The negative case — no page, no link — is asserted by the no-icon test
	// above; the settings loader memoises per request, so this test creates
	// the page before its first render rather than re-bootstrapping.
	$container = $this->app->getContainer();
	$container->get(BuilderInstaller::class)->ensurePagesCollection();
	$container->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'manifest', 'title' => 'Manifest', 'route' => '/manifest.webmanifest', 'template' => 'manifest']);

	expect(($this->render)('{{ cms.seo.head() }}'))->toContain('<link rel="manifest" href="http://totalcms.test/manifest.webmanifest">');
	expect(($this->render)('{{ cms.seo.icons() }}'))->toBe('<link rel="manifest" href="http://totalcms.test/manifest.webmanifest">');
});

it('renders a full head for an ad-hoc array, the shape a Stacks page hands over', function (): void {
	$html = ($this->render)("{{ cms.seo.head({title: 'Pricing', description: 'What it costs.', image: '/images/pricing.png', url: '/pricing'}) }}");

	expect($html)->toContain('<title>Pricing | Bistro</title>')
		->toContain('<link rel="canonical" href="http://totalcms.test/pricing">')
		->toContain('content="What it costs."')
		->toContain('og:image" content="http://totalcms.test/images/pricing.png"')
		->toContain('"WebPage"');
});

<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Seo\Data\ShareCard;
use TotalCMS\Domain\Seo\Data\SocialCardReason;
use TotalCMS\Domain\Seo\Service\ShareCardPreviewer;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

// The Share Cards utility's engine: every Site Builder page and every object
// of a collection the Site Builder serves, rendered through its real template,
// with the card it shares and why.

/** A page whose head comes from a record. */
const HEAD_OBJECT = '<html><head>{{ cms.seo.head(object, {collection: "%s"}) }}</head><body></body></html>';

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();

	// Before boot: the Twig loader takes its paths when it is built.
	$templates = [
		'blog'   => sprintf(HEAD_OBJECT, 'blog'),
		'notes'  => '{% if object.draft %}{{ cms.notFound() }}{% endif %}' . sprintf(HEAD_OBJECT, 'notes'),
		'broken' => '<html><head>{{ undefined_function_here() }}</head></html>',
		'merged' => '{% set object = object|merge({title: "Total CMS: " ~ object.title}) %}' . sprintf(HEAD_OBJECT, 'merged'),
		'twice'  => '<html><head>{{ cms.seo.head(object, {collection: "twice"}) }}{{ cms.seo.head({title: "Handmade"}) }}</head></html>',
		'about'  => '<html><head>{% set p = cms.builder.page() %}{{ cms.seo.head(p) }}</head></html>',
		'feed'   => '<rss>{{ page.title }}</rss>',
		'cdn'    => '<html><head><meta property="og:image" content="https://cdn.example.net/share/a.jpg"><meta property="og:title" content="CDN"><meta property="og:description" content="Hosted elsewhere."></head></html>',
	];
	is_dir(cmsDataDir() . 'builder/pages') || mkdir(cmsDataDir() . 'builder/pages', 0755, true);
	foreach ($templates as $name => $body) {
		file_put_contents(templatePath($name, 'pages'), $body);
	}

	$this->setUpApp(bootstrap());
	$c       = $this->app->getContainer();
	$this->c = $c;

	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$c->get(ObjectSaver::class)->saveObject('seo-site', [
		'id'                 => 'seo-site',
		'siteName'           => 'Bistro',
		'defaultImage'       => ['name' => 'share.jpg', 'size' => 10, 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'],
		'socialImageOptions' => ['marks' => [['marktext' => '${socialTitle}']]],
	]);

	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$c->get(CollectionSaver::class)->patchCollection('blog', ['url' => '/blog/{id}', 'prettyUrl' => true]);
	foreach (['notes', 'broken', 'merged', 'twice', 'products'] as $id) {
		$c->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => ucfirst($id), 'schema' => 'blog', 'url' => "/{$id}/{id}", 'prettyUrl' => true]);
	}

	$this->save = fn (string $collection, array $object) => $c->get(ObjectSaver::class)->saveObject($collection, $object);
	($this->save)('blog', ['id' => 'hello', 'title' => 'Hello', 'summary' => 'A first post.']);
	($this->save)('blog', ['id' => 'wip', 'title' => 'Work in Progress', 'draft' => true]);
	($this->save)('notes', ['id' => 'wip', 'title' => 'A Draft Note', 'summary' => 'Not out yet.', 'draft' => true]);
	($this->save)('broken', ['id' => 'one', 'title' => 'Broken', 'summary' => 'Fails to render.']);
	($this->save)('merged', ['id' => 'release', 'title' => '3.6']);
	($this->save)('twice', ['id' => 'two', 'title' => 'Two Heads']);
	($this->save)('products', ['id' => 'mug', 'title' => 'Mug']);

	$c->get(BuilderInstaller::class)->ensurePagesCollection();
	($this->save)('builder-pages', ['id' => 'about', 'title' => 'About Us', 'route' => '/about', 'template' => 'about', 'seo' => ['noindex' => true]]);
	($this->save)('builder-pages', ['id' => 'feed', 'title' => 'Feed', 'route' => '/feed.xml', 'template' => 'feed']);
	($this->save)('builder-pages', ['id' => 'soon', 'title' => 'Coming Soon', 'route' => '/soon', 'template' => 'about', 'draft' => true]);
	($this->save)('builder-pages', ['id' => 'cdn', 'title' => 'CDN', 'route' => '/cdn', 'template' => 'cdn']);
	($this->save)('builder-pages', ['id' => 'post', 'title' => 'Post', 'route' => '/posts/{id}', 'template' => 'about']);
	($this->save)('builder-pages', ['id' => 'moved', 'title' => 'Moved', 'route' => '/old', 'template' => 'about', 'status' => 301, 'redirectTo' => '/about']);
	$c->get(IndexBuilder::class)->buildIndex('builder-pages');

	$this->previewer = $c->get(ShareCardPreviewer::class);
	$this->card      = function (string $section, string $path): ?ShareCard {
		foreach ($this->previewer->chunk($section, 0)['cards'] as $card) {
			if ($card->path === $path) {
				return $card;
			}
		}

		return null;
	};
});

it('lists Pages first, then each collection the Site Builder serves, and names the ones it skips', function (): void {
	$result = $this->previewer->sections();
	$ids    = array_map(fn ($s) => $s->id, $result['sections']);

	expect($ids[0])->toBe('builder-pages')
		->and($ids)->toContain('blog', 'notes', 'broken', 'merged', 'twice')
		->and($ids)->not->toContain('products')
		->and($result['skipped'])->toBe(['products']);

	$pages = $result['sections'][0];
	$blog  = $result['sections'][array_search('blog', $ids, true)];
	expect($pages->count)->toBe(4) // about, cdn, feed and the draft soon: no dynamic route, no redirect
		->and($pages->settingsUrl)->toBe('collections/seo-site/seo-site')
		->and($blog->count)->toBe(2)
		->and($blog->settingsUrl)->toBe('collections/blog/edit');
});

it('shows a drawn card, and a draft with its badges from the record', function (): void {
	$hello = ($this->card)('blog', '/blog/hello');
	$wip   = ($this->card)('blog', '/blog/wip');

	expect($hello?->head->reason)->toBe(SocialCardReason::Drawn)
		->and($hello?->head->ogTitle)->toContain('Hello')
		->and($hello?->editUrl)->toBe('collections/blog/hello')
		->and($hello?->imageSrc)->toStartWith('/imageworks/social/blog/hello.jpg?v=')
		->and($hello?->draft)->toBeFalse()
		->and($hello?->noindex)->toBeFalse()
		->and($wip?->draft)->toBeTrue()
		->and(($this->card)('builder-pages', '/about')?->noindex)->toBeTrue();
});

it('catches a template that changes a drawn value before the head', function (): void {
	$card = ($this->card)('merged', '/merged/release');

	expect($card?->head->reason)->toBe(SocialCardReason::HashMismatch)
		->and($card?->isProblem())->toBeTrue();
});

it('works out a card from the record when the template refuses to render', function (): void {
	$card = ($this->card)('notes', '/notes/wip');

	expect($card?->head->fromRecord)->toBeTrue()
		->and($card?->head->note)->toBe('refused to render')
		->and($card?->head->ogTitle)->toContain('A Draft Note');
});

it('reports a broken template without its stack trace', function (): void {
	$card = ($this->card)('broken', '/broken/one');

	expect($card?->head->fromRecord)->toBeTrue()
		->and($card?->head->note)->not->toBe('')
		->and($card?->head->note)->not->toContain('#0')
		->and(strlen((string)$card?->head->note))->toBeLessThanOrEqual(200);
});

it('says a page without a head shares no image, without failing', function (): void {
	$card = ($this->card)('builder-pages', '/feed.xml');

	expect($card?->head->reason)->toBe(SocialCardReason::NoImage)
		->and($card?->head->fromRecord)->toBeFalse()
		->and($card?->editUrl)->toBe('builder/page/feed');
});

it('renders a page at its own URL, so cms.builder.page() finds the record', function (): void {
	$card = ($this->card)('builder-pages', '/about');

	expect($card?->head->ogTitle)->toContain('About Us')
		->and($card?->head->reason)->toBe(SocialCardReason::Drawn);
});

it('puts the request URI and current URL back after every chunk', function (): void {
	$adapter                = $this->c->get(TotalCMSTwigAdapter::class);
	$_SERVER['REQUEST_URI'] = '/admin/utils/share-cards';
	$adapter->currentUrl    = '/admin/utils/share-cards';

	$this->previewer->chunk('broken', 0);
	$this->previewer->chunk('builder-pages', 0);

	expect($_SERVER['REQUEST_URI'])->toBe('/admin/utils/share-cards')
		->and($adapter->currentUrl)->toBe('/admin/utils/share-cards');
});

// Social sites read the first og:image; the last head here is a handmade one.
it('reads the outcome of the head whose image the page shares', function (): void {
	$card = ($this->card)('twice', '/twice/two');

	expect($card?->head->reason)->toBe(SocialCardReason::Drawn);
});

it('returns cards 25 at a time', function (): void {
	for ($i = 1; $i <= 30; $i++) {
		($this->save)('notes', ['id' => "n{$i}", 'title' => "Note {$i}"]);
	}

	$first  = $this->previewer->chunk('notes', 0);
	$second = $this->previewer->chunk('notes', 25);

	expect($first['cards'])->toHaveCount(25)
		->and($first['next'])->toBe(25)
		->and($second['cards'])->toHaveCount(6)
		->and($second['next'])->toBeNull();
});

it('shows shares on the site address and loads images from this install', function (): void {
	// Site SEO loads once per request: save the address, then start a fresh app.
	$current = $this->c->get(ObjectFetcher::class)->fetchObject('seo-site', 'seo-site')->toArray();
	$this->c->get(ObjectUpdater::class)->updateObject('seo-site', 'seo-site', ['baseUrl' => 'https://www.example.com'] + $current);
	$this->setUpApp(bootstrap());
	$this->previewer = $this->app->getContainer()->get(ShareCardPreviewer::class);

	$card = ($this->card)('blog', '/blog/hello');

	expect($card?->domain)->toBe('www.example.com')
		->and($card?->shareUrl)->toBe('https://www.example.com/blog/hello')
		->and($card?->imageSrc)->toStartWith('/imageworks/social/blog/hello.jpg?v=');
});

it('throws for a section it does not know', function (): void {
	$this->previewer->chunk('nope', 0);
})->throws(DomainException::class);

// Review fixes.

it('keeps a share image that lives on another host', function (): void {
	$card = ($this->card)('builder-pages', '/cdn');

	expect($card?->imageSrc)->toBe('https://cdn.example.net/share/a.jpg');
});

it('finds a draft page from cms.builder.page(), which the router alone skips', function (): void {
	$card = ($this->card)('builder-pages', '/soon');

	expect($card?->head->ogTitle)->toContain('Coming Soon')
		->and($card?->head->reason)->toBe(SocialCardReason::Drawn)
		->and($card?->draft)->toBeTrue();
});

it('counts a template that fails, not one that declines to render', function (): void {
	$broken = ($this->card)('broken', '/broken/one');
	$draft  = ($this->card)('notes', '/notes/wip');

	expect($broken?->head->failed)->toBeTrue()
		->and($broken?->isProblem())->toBeTrue()
		->and($draft?->head->failed)->toBeFalse()
		->and($draft?->isProblem())->toBeFalse();
});

it('does not count a page that is not an HTML document', function (): void {
	$feed = ($this->card)('builder-pages', '/feed.xml');

	expect($feed?->head->document)->toBeFalse()
		->and($feed?->isProblem())->toBeFalse();
});


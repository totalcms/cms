<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function TotalCMS\Slim\Pest\get;

// The Share Cards utility page (Utilities → Reporting) and the endpoint that
// renders its cards a chunk at a time.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	restoreFixtures();

	// Before boot: the Twig loader takes its paths when it is built.
	is_dir(cmsDataDir() . 'builder/pages') || mkdir(cmsDataDir() . 'builder/pages', 0755, true);
	file_put_contents(templatePath('blog', 'pages'), '<html><head>{{ cms.seo.head(object, {collection: "blog"}) }}</head></html>');
	file_put_contents(templatePath('about', 'pages'), '<html><head>{{ cms.seo.head(page) }}</head></html>');

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
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'hello', 'title' => 'Hello', 'summary' => 'A first post.']);
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'tom-jerry', 'title' => 'Tom & "Jerry" <b>bold</b>']);

	$c->get(BuilderInstaller::class)->ensurePagesCollection();
	$c->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'about', 'title' => 'About Us', 'route' => '/about', 'template' => 'about']);
	$c->get(IndexBuilder::class)->buildIndex('builder-pages');
});

it('renders the page with a section per collection and no missing translations', function (): void {
	$response = get('/admin/utils/share-cards');
	$html     = (string)$response->getBody();

	expect($response->getStatusCode())->toBe(200)
		->and($html)->toContain('Share Cards')
		->and($html)->toContain('data-section="blog"')
		->and($html)->toContain('data-section="builder-pages"')
		->and($html)->not->toMatch('/share_cards\.[a-z_.-]+/');
});

it('gives the page script a hook nothing else on the page shares', function (): void {
	$html = (string)get('/admin/utils/share-cards')->getBody();

	// The sidebar menu prints each utility as <li class="{path}">, so the
	// class share-cards is taken; the script must find its root another way.
	expect(substr_count($html, 'data-share-cards'))->toBe(2) // the section, and the script's selector
		->and($html)->toContain("document.querySelector('[data-share-cards]')")
		->and($html)->not->toContain("document.querySelector('.share-cards')");
});

it('renders a chunk of cards with the offset of the next one', function (): void {
	$response = get('/admin/utils/share-cards/cards?section=blog&offset=0');
	$html     = (string)$response->getBody();

	expect($response->getStatusCode())->toBe(200)
		->and(substr_count($html, 'class="share-card '))->toBe(2)
		->and($response->getHeaderLine('X-Share-Cards-Next'))->toBe('')
		->and($response->getHeaderLine('X-Share-Cards-Problems'))->toMatch('/^\d+$/')
		->and($html)->not->toMatch('/share_cards\.[a-z_.-]+/');
});

it('leaves out a page that is not HTML: a feed has no card to check', function (): void {
	file_put_contents(templatePath('feed', 'pages'), '<rss></rss>');
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'feed', 'title' => 'Feed', 'route' => '/feed.xml', 'template' => 'feed']);
	$this->app->getContainer()->get(IndexBuilder::class)->buildIndex('builder-pages');

	$html = (string)get('/admin/utils/share-cards/cards?section=builder-pages&offset=0')->getBody();

	expect($html)->toContain('/about')
		->and($html)->not->toContain('/feed.xml')
		->and(substr_count($html, 'class="share-card '))->toBe(1);
});

it('answers 404 for a section it does not know', function (): void {
	expect(get('/admin/utils/share-cards/cards?section=nope&offset=0')->getStatusCode())->toBe(404);
});

it('escapes what a record says and encodes its edit link', function (): void {
	$html = (string)get('/admin/utils/share-cards/cards?section=blog&offset=0')->getBody();

	expect($html)->not->toContain('<b>bold</b>')
		->and($html)->toContain('&lt;b&gt;bold&lt;/b&gt;')
		->and($html)->toContain('href="collections/blog/tom-jerry"');
});

it('says the utility needs the Site Builder when there are no builder pages', function (): void {
	$this->c->get(CollectionRemover::class)->deleteCollection('builder-pages');

	$html = (string)get('/admin/utils/share-cards')->getBody();

	expect($html)->toContain('share-cards-needs-builder')
		->and($html)->not->toContain('data-section=');
});

it('has every Share Cards string in every locale', function (): void {
	$en   = require dirname(__DIR__, 2) . '/resources/translations/admin.en_US.php';
	$keys = array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, 'share_cards.') || $k === 'access_groups.util_share_cards');

	expect(count($keys))->toBeGreaterThan(20);

	foreach (glob(dirname(__DIR__, 2) . '/resources/translations/admin.*.php') ?: [] as $file) {
		$locale = require $file;
		foreach ($keys as $key) {
			expect(array_key_exists($key, $locale))->toBeTrue(basename($file) . ' is missing ' . $key);
		}
	}
});

it('is a Pro feature: below Pro the page is refused and so are its cards', function (): void {
	$this->c->set(EditionFeatureService::class, editionsFor(Edition::STANDARD));

	$page  = (string)get('/admin/utils/share-cards')->getBody();
	$chunk = get('/admin/utils/share-cards/cards?section=blog&offset=0');

	expect($page)->toContain('requires the Pro edition')
		->and($page)->not->toContain('data-share-cards')
		->and($chunk->getStatusCode())->toBe(403);
});

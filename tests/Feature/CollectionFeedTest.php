<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Migration\Migration\EnableCollectionFeedsMigration;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

use function TotalCMS\Slim\Pest\get;

/**
 * `/feed/rss/{collection}` used to serve every collection that had a URL, and
 * the query string chose which fields appeared. A collection now publishes a
 * feed only when its RSS Feed card is enabled; the upgrade migration turns
 * the card on for blog and feed collections and for nothing else.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();

	$this->container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'article',
		'type'       => 'object',
		'properties' => [
			'id'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'title'   => ['type' => 'string', 'field' => 'text'],
			'summary' => ['type' => 'string', 'field' => 'text'],
			'secret'  => ['type' => 'string', 'field' => 'text'],
			'draft'   => ['type' => 'boolean', 'field' => 'toggle'],
			'retired' => ['type' => 'boolean', 'field' => 'toggle'],
		],
		'index' => ['id', 'title', 'summary', 'secret', 'draft', 'retired'],
	]);

	/** @param array<string,mixed> $feed */
	$this->articles = function (array $feed = []): void {
		$this->container->get(CollectionSaver::class)->saveCollection(
			['id' => 'articles', 'name' => 'Articles', 'schema' => 'article', 'url' => '/articles/{id}'] + ($feed === [] ? [] : ['feed' => $feed]),
		);

		$saver = $this->container->get(ObjectSaver::class);
		$saver->saveObject('articles', ['id' => 'live', 'title' => 'Published Article', 'summary' => 'Public summary', 'secret' => 'internal-note']);
		$saver->saveObject('articles', ['id' => 'wip', 'title' => 'Draft Article', 'summary' => 'Not ready', 'draft' => true]);
		$saver->saveObject('articles', ['id' => 'old', 'title' => 'Retired Article', 'summary' => 'Gone', 'retired' => true]);
	};

	$this->storedFeed = fn (string $id): mixed => json_decode(
		(string)file_get_contents(cmsDataDir() . "$id/.meta.json"),
		true,
	)['feed'] ?? null;
});

it('answers 404 for a collection that has not enabled its feed', function (): void {
	($this->articles)();

	expect(get('/feed/rss/articles')->getStatusCode())->toBe(404);
	// A parameterized URL is no way in either.
	expect(get('/feed/rss/articles?limit=5&content=secret')->getStatusCode())->toBe(404);
	expect(get('/feed/rss/no-such-collection')->getStatusCode())->toBe(404);
});

it('serves an enabled collection, without drafts', function (): void {
	($this->articles)(['enabled' => true, 'name' => 'Articles Feed']);

	$response = get('/feed/rss/articles');
	$body     = (string)$response->getBody();

	expect($response->getStatusCode())->toBe(200);
	expect($body)->toContain('Articles Feed')->toContain('Published Article')->toContain('Public summary');
	expect($body)->toContain('Retired Article')->not->toContain('Draft Article');
});

it('hides by the collection\'s Hidden Field in place of draft', function (): void {
	($this->articles)(['enabled' => true, 'hidden' => 'retired']);

	$body = (string)get('/feed/rss/articles')->getBody();

	expect($body)->toContain('Published Article')->toContain('Draft Article');
	expect($body)->not->toContain('Retired Article');

	// The mapping is the collection's to set, never the request's.
	expect((string)get('/feed/rss/articles?hidden=draft')->getBody())->toContain('Draft Article');
});

it('does not let the query string choose fields or reveal drafts', function (): void {
	($this->articles)(['enabled' => true]);

	$body = (string)get('/feed/rss/articles?content=secret&title=secret&exclude=title:zzz&draft=nope')->getBody();

	expect($body)->toContain('Published Article')->toContain('Public summary');
	expect($body)->not->toContain('internal-note')->not->toContain('Draft Article');
});

it('lets the query string narrow and rename an enabled feed', function (): void {
	($this->articles)(['enabled' => true, 'name' => 'Articles Feed']);

	$body = (string)get('/feed/rss/articles?name=Just%20One&limit=1')->getBody();

	expect($body)->toContain('<title>Just One</title>');
	expect(substr_count($body, '<item>'))->toBe(1);
});

it('creates blog and feed collections with the feed already on', function (): void {
	$fetcher = $this->container->get(CollectionFetcher::class);

	expect($fetcher->fetchOrCreateReserved('blog')?->feed)->toBe(['enabled' => true]);
	expect($fetcher->fetchOrCreateReserved('feed')?->feed)->toBe(['enabled' => true]);
	expect($fetcher->fetchOrCreateReserved('gallery')?->feed)->toBe([]);
});

it('migrates blog and feed collections on, and leaves every other collection off', function (): void {
	$saver = $this->container->get(CollectionSaver::class);
	// Collections as an upgraded site has them: no feed card at all.
	$saver->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog', 'url' => '/news/{id}']);
	$saver->saveCollection(['id' => 'links', 'name' => 'Links', 'schema' => 'feed']);
	($this->articles)();

	$changed = $this->container->get(EnableCollectionFeedsMigration::class)->run();

	expect($changed)->toBe(2);
	expect(($this->storedFeed)('news'))->toBe(['enabled' => true]);
	expect(($this->storedFeed)('links'))->toBe(['enabled' => true]);
	expect(($this->storedFeed)('articles'))->toBeNull();
	expect(get('/feed/rss/articles')->getStatusCode())->toBe(404);
});

it('migrates a collection whose schema inherits from blog', function (): void {
	$this->container->get(SchemaSaver::class)->saveSchema([
		'id'          => 'journal',
		'type'        => 'object',
		'inheritFrom' => ['blog'],
		'properties'  => ['mood' => ['type' => 'string', 'field' => 'text']],
	]);
	$this->container->get(CollectionSaver::class)->saveCollection(['id' => 'journal', 'name' => 'Journal', 'schema' => 'journal']);

	$this->container->get(EnableCollectionFeedsMigration::class)->run();

	expect(($this->storedFeed)('journal'))->toBe(['enabled' => true]);
});

it('leaves a feed someone already switched off, and keeps other card settings', function (): void {
	$saver = $this->container->get(CollectionSaver::class);
	$saver->saveCollection(['id' => 'news', 'name' => 'News', 'schema' => 'blog', 'feed' => ['enabled' => false]]);
	$saver->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'blog', 'feed' => ['name' => 'Notes Feed']]);

	$changed = $this->container->get(EnableCollectionFeedsMigration::class)->run();

	expect($changed)->toBe(1);
	expect(($this->storedFeed)('news')['enabled'])->toBeFalse();
	expect(($this->storedFeed)('notes'))->toMatchArray(['enabled' => true, 'name' => 'Notes Feed']);

	// Running again changes nothing.
	expect($this->container->get(EnableCollectionFeedsMigration::class)->run())->toBe(0);
});

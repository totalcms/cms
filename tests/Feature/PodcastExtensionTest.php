<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;

use function TotalCMS\Slim\Pest\get;

/**
 * The bundled podcast extension end to end: its two schemas back a singleton
 * show collection and an episodes collection the show names, and the same
 * feed comes out of the public route and of `podcast_feed()`. The mapper's
 * rules are unit-tested; this proves the wiring — the extension loads, its
 * schemas resolve, the show's episodes setting is honoured, the route serves
 * RSS and the Twig function renders it.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// Enable the bundled extension before the app boots: the state file is
	// what ExtensionManager reads, and an enabled state with no permissions
	// recorded permits every capability.
	@mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/podcast' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$saver     = $container->get(CollectionSaver::class);

	$show            = new CollectionData();
	$show->id        = 'podcast';
	$show->name      = 'Podcast';
	$show->schema    = 'podcast';
	$show->singleton = true;
	$saver->saveCollection($show->toArray());

	$episodes         = new CollectionData();
	$episodes->id     = 'episodes';
	$episodes->name   = 'Episodes';
	$episodes->schema = 'podcast-episode';
	$episodes->url    = '/episodes/';
	$saver->saveCollection($episodes->toArray());

	$objects = $container->get(ObjectSaver::class);
	$objects->saveObject('podcast', [
		'id'          => 'podcast',
		'title'       => 'The Show',
		'episodes'    => 'episodes',
		'description' => 'About the show',
		'author'      => 'Joe Workman',
		'ownerEmail'  => 'joe@example.com',
		'cover'       => ['name' => 'cover.png', 'mime' => 'image/png', 'alt' => 'Cover', 'exif' => ['nodata' => ''], 'featured' => false, 'focalpoint' => ['x' => 50, 'y' => 50], 'link' => '', 'tags' => []],
		'categories'  => ['Technology', 'Business > Entrepreneurship'],
		'explicit'    => false,
		'type'        => 'episodic',
		'created'     => '2026-08-01T00:00:00+00:00',
		'updated'     => '2026-08-01T00:00:00+00:00',
	]);

	$audio = ['name' => 'ep.mp3', 'mime' => 'audio/mpeg', 'size' => 4242, 'uploadDate' => '2026-08-01T00:00:00+00:00'];
	foreach ([
		['id' => 'ep-1', 'title' => 'Episode One', 'date' => '2026-08-01', 'draft' => false, 'episode' => 1],
		['id' => 'ep-2', 'title' => 'Episode Two', 'date' => '2026-08-15', 'draft' => false, 'episode' => 2],
		['id' => 'draft', 'title' => 'Unfinished', 'date' => '2026-08-20', 'draft' => true, 'episode' => 3],
		['id' => 'future', 'title' => 'Next Year', 'date' => '2099-01-01', 'draft' => false, 'episode' => 4],
	] as $episode) {
		$objects->saveObject('episodes', $episode + [
			'audio'    => $audio,
			'duration' => 1800,
			'content'  => '<p>Notes</p>',
			'created'  => '2026-08-01T00:00:00+00:00',
			'updated'  => '2026-08-01T00:00:00+00:00',
		]);
	}

	$this->render = fn (string $template): string => $container->get(TwigEngine::class)->renderString($template, []);
});

function assertPodcastFeed(string $xml, string $selfPath = '/api/ext/totalcms/podcast/feed'): void
{
	// The feed's own address is where it is served: the route by default, and
	// the page's URL when a page renders it. The GUID derives from it. The
	// writer makes the path absolute on the site's origin; the path is what
	// this asserts.
	expect($xml)->toMatch('~<atom:link rel="self"[^>]*href="https?://totalcms\.test' . preg_quote($selfPath, '~') . '"~');
	expect($xml)->toContain('<itunes:author>Joe Workman</itunes:author>');
	expect($xml)->toContain('<itunes:email>joe@example.com</itunes:email>');
	expect($xml)->toContain('/imageworks/podcast/podcast/cover.png"');
	expect($xml)->toMatch('#<itunes:category text="Business">\s*<itunes:category text="Entrepreneurship"/>#');
	expect($xml)->toMatch('#<podcast:guid>[0-9a-f-]{36}</podcast:guid>#');

	$feed = simplexml_load_string($xml);
	expect($feed)->not->toBeFalse();
	$titles = [];
	foreach ($feed->channel->item as $item) {
		$titles[] = (string)$item->title;
	}
	// Newest first; the draft and the future-dated episode are held back.
	expect($titles)->toBe(['Episode Two', 'Episode One']);
	expect((string)$feed->channel->item[0]->enclosure['url'])->toEndWith('/stream/episodes/ep-2/audio');
	expect((string)$feed->channel->item[0]->enclosure['type'])->toBe('audio/mpeg');
	expect((string)$feed->channel->item[0]->enclosure['length'])->toBe('4242');
	expect($xml)->toContain('<itunes:duration>1800</itunes:duration>');
	expect($xml)->toContain('<itunes:episode>2</itunes:episode>');
}

it('serves the feed at the public route, with no page involved', function (): void {
	$response = get('/api/ext/totalcms/podcast/feed');

	expect($response->getStatusCode())->toBe(200);
	expect($response->getHeaderLine('Content-Type'))->toStartWith('application/rss+xml');
	expect($response->getHeaderLine('Cache-Control'))->toBe('public, max-age=300');
	assertPodcastFeed((string)$response->getBody());
});

it('serves any show by its collection at /feed/{show}', function (): void {
	$response = get('/api/ext/totalcms/podcast/feed/podcast');

	expect($response->getStatusCode())->toBe(200);
	assertPodcastFeed((string)$response->getBody(), '/api/ext/totalcms/podcast/feed/podcast');
});

it('renders the same feed from podcast_feed() in a template', function (): void {
	// Outside a request there is no page URL, so the route stands in as the address.
	$xml = ($this->render)('{{ podcast_feed() }}');
	assertPodcastFeed($xml);
	expect(($this->render)("{{ podcast_feed('podcast') }}"))->toBe($xml);
	// A page rendering the feed passes its own address through `self`.
	assertPodcastFeed(($this->render)("{{ podcast_feed('podcast', {self: '/podcast.xml'}) }}"), '/podcast.xml');
});

it('is a 404 that names the show when the show has no record', function (): void {
	$response = get('/api/ext/totalcms/podcast/feed/nope');

	expect($response->getStatusCode())->toBe(404);
	expect((string)$response->getBody())->toContain("'nope'");
});

it('renders nothing, rather than breaking the page, for a show without a record', function (): void {
	// Extension Twig functions run fault-isolated: the DomainException the
	// feed throws is logged to the extensions channel and the call renders
	// empty, so a broken feed cannot take a page down with it. The message
	// itself is covered by the route (a 404) and by PodcastFeedTest.
	expect(($this->render)("{{ podcast_feed('nope') }}"))->toBe('');
});

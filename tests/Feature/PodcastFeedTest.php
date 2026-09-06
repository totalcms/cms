<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Adapter\FeedTwigAdapter;

/**
 * cms.feed.podcast() end to end: a singleton show collection from the podcast
 * schema, an episodes collection from podcast-episode, one call, one valid
 * feed. The mapper's rules are unit-tested; this proves the wiring — schema
 * validation accepts the shapes, the singleton id resolves, index rows carry
 * what the mapper reads, and the writer renders the result.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
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
		'description' => 'About the show',
		'author'      => 'Joe Workman',
		'ownerEmail'  => 'joe@example.com',
		'feedUrl'     => 'https://example.com/podcast.xml',
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

	$this->feed = $container->get(FeedTwigAdapter::class);
});

it('renders a podcast feed from the two collections in one call', function (): void {
	$xml = (string)$this->feed->podcast('podcast', 'episodes');

	expect($xml)->toContain('href="https://example.com/podcast.xml"');

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
});

it('names the missing show when the singleton has no record', function (): void {
	$this->feed->podcast('nope', 'episodes');
})->throws(DomainException::class, "'nope'");

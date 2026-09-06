<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\PodcastFeedMapper;
use TotalCMS\Support\Config;

/**
 * The mapper is the whole of cms.feed.podcast() minus fetching and rendering:
 * show + episode rows in, FeedWriter meta/items out. Every rule about what is
 * published and how a field lands in the feed lives here.
 */
function podcastMapper(): PodcastFeedMapper
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->api = '/tcms';

	return new PodcastFeedMapper($config);
}

/** @return array<string,mixed> */
function podcastShow(array $overrides = []): array
{
	return array_merge([
		'id'          => 'podcast',
		'title'       => 'The Show',
		'description' => '<p>About <b>the</b> show</p>',
		'author'      => 'Joe Workman',
		'ownerEmail'  => 'joe@example.com',
		'feedUrl'     => 'https://example.com/show.xml',
		'cover'       => ['name' => 'cover.png', 'mime' => 'image/png'],
		'categories'  => ['Technology', 'Business > Entrepreneurship'],
		'explicit'    => false,
		'type'        => 'episodic',
	], $overrides);
}

/** @return array<string,mixed> */
function podcastEpisodeRow(array $overrides = []): array
{
	return array_merge([
		'id'          => 'ep-1',
		'title'       => 'Episode One',
		'date'        => '2026-08-01',
		'draft'       => false,
		'audio'       => ['name' => 'ep1.mp3', 'mime' => 'audio/mpeg', 'size' => 12345],
		'duration'    => 1800,
		'episode'     => 1,
		'season'      => 0,
		'episodeType' => 'full',
		'content'     => '<p>Notes</p>',
		'summary'     => '',
		'art'         => [],
		'explicit'    => 'inherit',
		'audioUrl'    => '',
		'audioLength' => 0,
		'transcript'  => [],
		'chapters'    => [],
	], $overrides);
}

function mapPodcast(array $show, array $episodes, array $options = []): array
{
	return podcastMapper()->map('podcast', $show, 'episodes', $episodes, array_merge(['now' => '2026-09-01'], $options), fn (array $e): string => '/episodes/' . $e['id']);
}

describe('PodcastFeedMapper meta', function (): void {
	test('maps the show onto the feed meta and podcast block', function (): void {
		$meta = mapPodcast(podcastShow(), [])['meta'];

		expect($meta['title'])->toBe('The Show');
		expect($meta['description'])->toBe('About the show');
		expect($meta['link'])->toBe('/');
		expect($meta['self'])->toBe('https://example.com/show.xml');
		expect($meta['podcast']['author'])->toBe('Joe Workman');
		expect($meta['podcast']['owner'])->toBe(['name' => 'Joe Workman', 'email' => 'joe@example.com']);
		expect($meta['podcast']['image'])->toBe('/tcms/imageworks/podcast/podcast/cover.png');
		expect($meta['podcast']['category'])->toBe(['Technology', 'Business > Entrepreneurship']);
		expect($meta['podcast']['explicit'])->toBeFalse();
		expect($meta['podcast']['type'])->toBe('episodic');
		expect($meta['podcast'])->not->toHaveKeys(['funding', 'locked', 'newFeedUrl', 'guid']);
	});

	test('falls back to /podcast.xml when the show has no feed url', function (): void {
		$show = podcastShow();
		unset($show['feedUrl']);

		expect(mapPodcast($show, [])['meta']['self'])->toBe('/podcast.xml');
	});

	test('honours self, link, language and copyright options', function (): void {
		$meta = mapPodcast(podcastShow(), [], ['self' => '/feeds/show.xml', 'link' => '/show', 'language' => 'en-GB', 'copyright' => '© Joe'])['meta'];

		expect($meta['self'])->toBe('/feeds/show.xml');
		expect($meta['link'])->toBe('/show');
		expect($meta['language'])->toBe('en-GB');
		expect($meta['copyright'])->toBe('© Joe');
	});

	test('serves non-png artwork as jpg', function (): void {
		$meta = mapPodcast(podcastShow(['cover' => ['name' => 'cover.webp']]), [])['meta'];

		expect($meta['podcast']['image'])->toBe('/tcms/imageworks/podcast/podcast/cover.jpg');
	});

	test('adds funding, locked, newFeedUrl and guid only when set', function (): void {
		$meta = mapPodcast(podcastShow([
			'fundingUrl' => 'https://example.com/support',
			'locked'     => true,
			'newFeedUrl' => 'https://example.com/new.xml',
			'guid'       => '917393e3-1b1e-5cef-ace4-edaa54e1f810',
		]), [])['meta'];

		expect($meta['podcast']['funding'])->toBe(['url' => 'https://example.com/support', 'title' => 'Support the show']);
		expect($meta['podcast']['locked'])->toBe(['owner' => 'joe@example.com', 'value' => true]);
		expect($meta['podcast']['newFeedUrl'])->toBe('https://example.com/new.xml');
		expect($meta['podcast']['guid'])->toBe('917393e3-1b1e-5cef-ace4-edaa54e1f810');
	});
});

describe('PodcastFeedMapper items', function (): void {
	test('publishes only episodes with audio that are not drafts or in the future, newest first', function (): void {
		$items = mapPodcast(podcastShow(), [
			podcastEpisodeRow(['id' => 'old', 'date' => '2026-01-01']),
			podcastEpisodeRow(['id' => 'draft', 'draft' => true]),
			podcastEpisodeRow(['id' => 'future', 'date' => '2026-12-01']),
			podcastEpisodeRow(['id' => 'no-audio', 'audio' => []]),
			podcastEpisodeRow(['id' => 'linked', 'date' => '2026-07-01', 'audio' => [], 'audioUrl' => 'https://cdn.example.com/linked.mp3']),
			podcastEpisodeRow(['id' => 'new', 'date' => '2026-08-15']),
		])['items'];

		expect(array_column($items, 'id'))->toBe(['new', 'linked', 'old']);
	});

	test('an externally hosted episode uses the url, a guessed type and the supplied size', function (): void {
		$item = mapPodcast(podcastShow(), [podcastEpisodeRow(['audio' => [], 'audioUrl' => 'https://cdn.example.com/ep.m4a', 'audioLength' => 999])])['items'][0];

		expect($item['media'])->toBe(['url' => 'https://cdn.example.com/ep.m4a', 'type' => 'audio/mp4', 'length' => 999]);
	});

	test('an uploaded file wins over an audio url', function (): void {
		$item = mapPodcast(podcastShow(), [podcastEpisodeRow(['audioUrl' => 'https://cdn.example.com/ep.mp3'])])['items'][0];

		expect($item['media']['url'])->toBe('/tcms/stream/episodes/ep-1/audio');
	});

	test('uploaded transcript and chapters files are served through the download route', function (): void {
		$podcast = mapPodcast(podcastShow(), [podcastEpisodeRow([
			'transcript' => ['name' => 'ep1.srt', 'mime' => 'application/x-subrip', 'size' => 10],
			'chapters'   => ['name' => 'ep1.json', 'mime' => 'application/json', 'size' => 10],
		])])['items'][0]['podcast'];

		expect($podcast['transcript'])->toBe(['url' => '/tcms/download/episodes/ep-1/transcript', 'type' => 'application/srt']);
		expect($podcast['chapters'])->toBe(['url' => '/tcms/download/episodes/ep-1/chapters', 'type' => 'application/json+chapters']);
	});

	test('maps the enclosure from the stream route and the stored file', function (): void {
		$item = mapPodcast(podcastShow(), [podcastEpisodeRow()])['items'][0];

		expect($item['media'])->toBe(['url' => '/tcms/stream/episodes/ep-1/audio', 'type' => 'audio/mpeg', 'length' => 12345]);
		expect($item['link'])->toBe('/episodes/ep-1');
		expect($item['content'])->toBe('<p>Notes</p>');
		expect($item['author'])->toBe('Joe Workman');
		expect($item)->not->toHaveKey('summary');
	});

	test('carries the per-episode podcast block, omitting zero numbers and inherited explicit', function (): void {
		$podcast = mapPodcast(podcastShow(), [podcastEpisodeRow()])['items'][0]['podcast'];

		expect($podcast)->toBe(['episodeType' => 'full', 'duration' => 1800.0, 'episode' => 1]);
	});

	test('maps art, explicit override, transcript and chapters when present', function (): void {
		$podcast = mapPodcast(podcastShow(), [podcastEpisodeRow([
			'art'           => ['name' => 'ep1.jpg'],
			'explicit'      => 'yes',
			'season'        => 2,
			'transcriptUrl' => 'https://example.com/ep1.vtt',
			'chaptersUrl'   => 'https://example.com/ep1.chapters.json',
			'summary'       => '<em>Short</em>',
		])])['items'][0]['podcast'];

		expect($podcast['image'])->toBe('/tcms/imageworks/episodes/ep-1/art.jpg');
		expect($podcast['explicit'])->toBeTrue();
		expect($podcast['season'])->toBe(2);
		expect($podcast['transcript'])->toBe(['url' => 'https://example.com/ep1.vtt', 'type' => 'text/vtt']);
		expect($podcast['chapters'])->toBe(['url' => 'https://example.com/ep1.chapters.json', 'type' => 'application/json+chapters']);
	});

	test('a clean override maps to explicit false and a plain summary is kept', function (): void {
		$item = mapPodcast(podcastShow(), [podcastEpisodeRow(['explicit' => 'no', 'summary' => '<em>Short</em>'])])['items'][0];

		expect($item['podcast']['explicit'])->toBeFalse();
		expect($item['summary'])->toBe('Short');
	});

	test('guesses transcript types from the extension', function (): void {
		foreach (['a.srt' => 'application/srt', 'a.json' => 'application/json', 'a.html' => 'text/html', 'a.txt' => 'text/plain'] as $file => $type) {
			$podcast = mapPodcast(podcastShow(), [podcastEpisodeRow(['transcriptUrl' => "https://example.com/{$file}"])])['items'][0]['podcast'];
			expect($podcast['transcript']['type'])->toBe($type);
		}
	});
});

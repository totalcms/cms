<?php

declare(strict_types=1);

use Laminas\Feed\Writer\Feed;
use Laminas\Feed\Writer\Writer;
use TotalCMS\Domain\Feed\Service\PodcastTags;

/**
 * PodcastTags maps our `podcast` arrays onto the Laminas iTunes and Podcast
 * Index writer extensions. Assertions are against the rendered XML, since the
 * renderer's output is what the directories read.
 */

/** Minimal valid channel block; tests override single keys. */
function podcastMeta(array $overrides = []): array
{
	return array_merge([
		'author'   => 'Joe Workman',
		'owner'    => ['name' => 'Joe Workman', 'email' => 'joe@example.com'],
		'image'    => 'https://example.com/cover.jpg',
		'category' => 'Technology',
		'explicit' => false,
	], $overrides);
}

function podcastAbsolute(): Closure
{
	return fn (string $u): string => str_starts_with($u, 'http') ? $u : 'https://example.com/' . ltrim($u, '/');
}

function podcastFeed(array $podcast, array $meta = []): Feed
{
	Writer::registerExtension('PodcastIndex');
	$feed = new Feed();
	$feed->setTitle('Show');
	$feed->setLink('https://example.com/');
	$feed->setDescription('A show.');
	$feed->setFeedLink('https://example.com/podcast.xml', 'rss');
	$feed->setDateModified(time());

	(new PodcastTags(podcastAbsolute()))->applyToFeed(
		$feed,
		$podcast,
		array_merge(['self' => 'https://example.com/podcast.xml', 'description' => 'A show.'], $meta),
	);

	return $feed;
}

function podcastXml(Feed $feed): string
{
	return $feed->export('rss');
}

function podcastEntry(array $podcast, array $item = []): string
{
	$feed  = podcastFeed(podcastMeta());
	$entry = $feed->createEntry();
	$entry->setTitle('Episode 1');
	$entry->setLink('https://example.com/ep/1');
	$entry->setDateModified(time());
	$entry->setDescription('Notes');

	(new PodcastTags(podcastAbsolute()))->applyToEntry($entry, $podcast, array_merge(['content' => '<p>Long <b>notes</b></p>'], $item));
	$feed->addEntry($entry);

	return $feed->export('rss');
}

describe('PodcastTags channel', function (): void {
	test('emits the itunes namespace and the five required tags', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta()));

		expect($xml)->toContain('xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"');
		expect($xml)->toContain('<itunes:author>Joe Workman</itunes:author>');
		expect($xml)->toContain('<itunes:name>Joe Workman</itunes:name>');
		expect($xml)->toContain('<itunes:email>joe@example.com</itunes:email>');
		expect($xml)->toContain('<itunes:image href="https://example.com/cover.jpg"/>');
		expect($xml)->toContain('<itunes:category text="Technology"/>');
		expect($xml)->toContain('<itunes:explicit>false</itunes:explicit>');
	});

	test('nests a Parent > Child category', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta(['category' => ['Business > Entrepreneurship', 'technology']])));

		expect($xml)->toMatch('#<itunes:category text="Business">\s*<itunes:category text="Entrepreneurship"/>\s*</itunes:category>#');
		expect($xml)->toContain('<itunes:category text="Technology"/>');
	});

	test('rejects an unknown category with suggestions', function (): void {
		podcastFeed(podcastMeta(['category' => 'Tecnology']));
	})->throws(DomainException::class, 'Technology');

	test('defaults summary to the feed description and type to episodic', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta()));

		expect($xml)->toContain('<itunes:summary>A show.</itunes:summary>');
		expect($xml)->toContain('<itunes:type>episodic</itunes:type>');
	});

	test('emits the optional channel tags when given', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta([
			'type'       => 'serial',
			'subtitle'   => 'Short',
			'summary'    => 'Long',
			'newFeedUrl' => 'https://example.com/new.xml',
			'complete'   => true,
			'block'      => true,
		])));

		expect($xml)->toContain('<itunes:type>serial</itunes:type>');
		expect($xml)->toContain('<itunes:subtitle>Short</itunes:subtitle>');
		expect($xml)->toContain('<itunes:summary>Long</itunes:summary>');
		expect($xml)->toContain('<itunes:new-feed-url>https://example.com/new.xml</itunes:new-feed-url>');
		expect($xml)->toContain('<itunes:complete>Yes</itunes:complete>');
		expect($xml)->toContain('<itunes:block>Yes</itunes:block>');
	});

	test('derives podcast:guid from the self url when omitted, and honours an explicit one', function (): void {
		$derived  = podcastXml(podcastFeed(podcastMeta()));
		$explicit = podcastXml(podcastFeed(podcastMeta(['guid' => '917393e3-1b1e-5cef-ace4-edaa54e1f810'])));

		expect($derived)->toContain('xmlns:podcast=');
		expect($derived)->toMatch('#<podcast:guid>[0-9a-f-]{36}</podcast:guid>#');
		expect($explicit)->toContain('<podcast:guid>917393e3-1b1e-5cef-ace4-edaa54e1f810</podcast:guid>');
	});

	test('emits locked and funding', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta([
			'locked'  => ['owner' => 'joe@example.com', 'value' => true],
			'funding' => [['url' => 'https://example.com/support', 'title' => 'Support the show']],
		])));

		expect($xml)->toContain('<podcast:locked owner="joe@example.com">yes</podcast:locked>');
		expect($xml)->toContain('<podcast:funding url="https://example.com/support">Support the show</podcast:funding>');
	});

	test('accepts a single funding hash as well as a list', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta(['funding' => ['url' => 'https://example.com/support', 'title' => 'Support']])));

		expect($xml)->toContain('<podcast:funding url="https://example.com/support">Support</podcast:funding>');
	});

	test('makes a relative image absolute', function (): void {
		$xml = podcastXml(podcastFeed(podcastMeta(['image' => '/media/cover.png'])));

		expect($xml)->toContain('href="https://example.com/media/cover.png"');
	});
});

describe('PodcastTags channel validation', function (): void {
	foreach (['author', 'owner', 'image', 'category', 'explicit'] as $key) {
		test("requires {$key}", function () use ($key): void {
			$meta = podcastMeta();
			unset($meta[$key]);
			podcastFeed($meta);
		})->throws(DomainException::class, "meta.podcast.{$key}");
	}

	test('requires meta.self so the guid and re-fetch link exist', function (): void {
		podcastFeed(podcastMeta(), ['self' => '']);
	})->throws(DomainException::class, 'meta.self');

	test('rejects an image that is not a plain .jpg or .png url', function (): void {
		// Ends in the letters "jpg", which is exactly the case Laminas' own
		// check would wave through and Apple would reject.
		podcastFeed(podcastMeta(['image' => 'https://example.com/imageworks?w=3000&f=jpg']));
	})->throws(DomainException::class, 'jpg');

	test('rejects a .jpeg image, since Apple wants jpg or png', function (): void {
		podcastFeed(podcastMeta(['image' => 'https://example.com/cover.jpeg']));
	})->throws(DomainException::class, 'jpg');

	test('rejects an unknown key, naming it', function (): void {
		podcastFeed(podcastMeta(['colour' => 'red']));
	})->throws(DomainException::class, 'colour');

	test('rejects a bad type', function (): void {
		podcastFeed(podcastMeta(['type' => 'weekly']));
	})->throws(DomainException::class, 'episodic');

	test('rejects an owner without an email', function (): void {
		podcastFeed(podcastMeta(['owner' => ['name' => 'Joe']]));
	})->throws(DomainException::class, 'owner');
});

describe('PodcastTags item', function (): void {
	test('emits duration in seconds, rounding floats and converting HH:MM:SS and MM:SS', function (): void {
		expect(podcastEntry(['duration' => 2714.3]))->toContain('<itunes:duration>2714</itunes:duration>');
		expect(podcastEntry(['duration' => 61]))->toContain('<itunes:duration>61</itunes:duration>');
		expect(podcastEntry(['duration' => '1:02:03']))->toContain('<itunes:duration>3723</itunes:duration>');
		expect(podcastEntry(['duration' => '02:03']))->toContain('<itunes:duration>123</itunes:duration>');
	});

	test('rejects a malformed duration', function (): void {
		podcastEntry(['duration' => '1 hour']);
	})->throws(DomainException::class, 'duration');

	test('emits episode, season and episodeType', function (): void {
		$xml = podcastEntry(['episode' => 12, 'season' => 2, 'episodeType' => 'bonus']);

		expect($xml)->toContain('<itunes:episode>12</itunes:episode>');
		expect($xml)->toContain('<itunes:season>2</itunes:season>');
		expect($xml)->toContain('<itunes:episodeType>bonus</itunes:episodeType>');
	});

	test('defaults episodeType to full and summary to text-stripped content', function (): void {
		$xml = podcastEntry([]);

		expect($xml)->toContain('<itunes:episodeType>full</itunes:episodeType>');
		expect($xml)->toContain('<itunes:summary>Long notes</itunes:summary>');
	});

	test('prefers item.summary over stripped content', function (): void {
		expect(podcastEntry([], ['summary' => 'Short']))->toContain('<itunes:summary>Short</itunes:summary>');
	});

	test('emits per-episode image, explicit, title, subtitle and block', function (): void {
		$xml = podcastEntry([
			'image'    => '/art/ep1.png',
			'explicit' => true,
			'title'    => 'Ep 1',
			'subtitle' => 'Sub',
			'block'    => true,
		]);

		expect($xml)->toContain('<itunes:image href="https://example.com/art/ep1.png"/>');
		expect($xml)->toContain('<itunes:explicit>true</itunes:explicit>');
		expect($xml)->toContain('<itunes:title>Ep 1</itunes:title>');
		expect($xml)->toContain('<itunes:subtitle>Sub</itunes:subtitle>');
		expect($xml)->toContain('<itunes:block>Yes</itunes:block>');
	});

	test('emits transcript, chapters, people and soundbites', function (): void {
		$xml = podcastEntry([
			'transcript' => ['url' => 'https://example.com/ep1.srt', 'type' => 'application/srt'],
			'chapters'   => ['url' => 'https://example.com/ep1.chapters.json', 'type' => 'application/json+chapters'],
			'people'     => [['name' => 'Joe', 'role' => 'host'], ['name' => 'Guest']],
			'soundbites' => [['startTime' => 73.0, 'duration' => 60.0, 'title' => 'Best bit']],
		]);

		expect($xml)->toContain('<podcast:transcript url="https://example.com/ep1.srt" type="application/srt"/>');
		expect($xml)->toContain('<podcast:chapters url="https://example.com/ep1.chapters.json" type="application/json+chapters"/>');
		expect($xml)->toContain('<podcast:person role="host">Joe</podcast:person>');
		expect($xml)->toContain('<podcast:person>Guest</podcast:person>');
		expect($xml)->toContain('<podcast:soundbite startTime="73" duration="60">Best bit</podcast:soundbite>');
	});

	test('rejects an unknown item key and a bad episodeType', function (): void {
		expect(fn () => podcastEntry(['length' => 3]))->toThrow(DomainException::class, 'length');
		expect(fn () => podcastEntry(['episodeType' => 'extra']))->toThrow(DomainException::class, 'full');
	});
});

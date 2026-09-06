<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\PodcastGuid;

/**
 * The Podcast Index guid is a UUIDv5 of the feed URL under a fixed namespace.
 * What matters to a podcaster is that it never changes for the life of the
 * show, so stability across scheme and trailing-slash variations is the
 * property under test.
 */
describe('PodcastGuid', function (): void {
	test('is stable and independent of scheme and trailing slashes', function (): void {
		$a = PodcastGuid::fromFeedUrl('https://example.com/podcast.xml');
		$b = PodcastGuid::fromFeedUrl('http://example.com/podcast.xml');
		$c = PodcastGuid::fromFeedUrl('https://example.com/podcast.xml/');

		expect($a)->toBe($b)->toBe($c);
		expect($a)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/');
	});

	test('differs for different feed urls', function (): void {
		expect(PodcastGuid::fromFeedUrl('https://example.com/a.xml'))
			->not->toBe(PodcastGuid::fromFeedUrl('https://example.com/b.xml'));
	});

	test('uses the podcast-namespace uuid namespace, so it matches other implementations', function (): void {
		// Reference value computed with python's uuid.uuid5(UUID('ead4c236-bf58-58c6-a2c6-a6b28d128cb6'),
		// 'example.com/podcast.xml'). Any conformant implementation must agree.
		expect(PodcastGuid::fromFeedUrl('https://example.com/podcast.xml'))
			->toBe(PodcastGuid::REFERENCE_EXAMPLE);
	});
});

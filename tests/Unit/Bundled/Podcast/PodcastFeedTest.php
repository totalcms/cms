<?php

declare(strict_types=1);

use TotalCMS\Bundled\Podcast\PodcastFeed;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Support\Config;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/podcast/PodcastFeedMapper.php';
require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/podcast/PodcastFeed.php';

// The two ways a show can fail to render, each with a message that says
// which. The happy path is covered end to end in tests/Feature/PodcastExtensionTest.php.
describe('PodcastFeed', function (): void {
	// Built in beforeEach so the closure is bound to the test case and can
	// create PHPUnit mocks.
	beforeEach(function (): void {
		$this->feed = function (array $show): PodcastFeed {
			$collections = $this->createMock(CollectionTwigAdapter::class);
			$collections->method('object')->willReturn($show);
			$collections->method('objects')->willReturn([]);
			$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();

			return new PodcastFeed($collections, new FeedWriter($config), $config);
		};
	});

	test('names the collection when the show has no record', function (): void {
		expect(fn () => ($this->feed)([])->render('nope'))->toThrow(DomainException::class, "'nope'");
	});

	test('says which setting is missing when the show names no episodes collection', function (): void {
		// The schema makes the field required, so this is the hand-edited or
		// imported record case — still a clear message rather than an empty feed.
		expect(fn () => ($this->feed)(['id' => 'podcast', 'title' => 'Show', 'episodes' => ''])->render('podcast'))
			->toThrow(DomainException::class, 'Episodes Collection');
	});
});

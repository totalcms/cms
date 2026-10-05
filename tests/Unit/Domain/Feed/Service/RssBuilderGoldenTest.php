<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;
use TotalCMS\Support\Config;

/**
 * The `/feed/rss/{collection}` document, pinned. RssBuilder is now a mapper
 * onto FeedWriter; this snapshot is what proved the move changed one thing a
 * reader can see — a relative enclosure URL is now absolute, which RSS
 * requires anyway. The channel pubDate is the wall clock and is normalized.
 */
function goldenRssBuilder(array $objects, array $feed = []): RssBuilder
{
	$collection         = test()->createMock(CollectionData::class);
	$collection->schema = 'blog';
	$collection->feed   = ['enabled' => true] + $feed;

	$collections = test()->createMock(CollectionFetcher::class);
	$collections->method('fetchCollection')->willReturn($collection);
	$index = test()->createMock(IndexFilter::class);
	$index->method('fetchFilteredIndex')->willReturn($objects);
	$urls = test()->createMock(ObjectUrlBuilder::class);
	$urls->method('buildUrl')->willReturnCallback(static fn (CollectionData $c, array $o): string => $o['id'] === 'broken' ? '' : '/blog/' . $o['id']);
	$urls->method('hasEmptySegments')->willReturn(false);

	$config         = test()->createMock(Config::class);
	$config->domain = 'example.com';
	$config->method('displayName')->willReturn('Example Site');

	return new RssBuilder($index, $collections, $urls, $config, new FeedWriter($config), test()->createMock(SchemaFetcher::class), new ParsedownMarkdown());
}

function goldenRss(string $xml): string
{
	// The channel's own pubDate is the wall clock; every item date is fixed.
	return (string)preg_replace('#<pubDate>[^<]+</pubDate>(\s*<generator>)#', '<pubDate>NOW</pubDate>$1', $xml);
}

test('the collection feed renders exactly as before', function (): void {
	$builder = goldenRssBuilder([
		['id' => 'older', 'title' => 'Older post', 'summary' => '<p>Body &amp; more</p>', 'author' => 'Joe', 'updated' => '2026-01-10T09:00:00+00:00', 'media' => 'https://cdn.example.com/a.mp3'],
		['id' => 'newest', 'title' => 'Newest post', 'summary' => 'Plain summary', 'updated' => '2026-03-01T12:30:00+00:00', 'media' => '/uploads/cover.png'],
		['id' => 'untitled', 'summary' => '', 'updated' => '2025-12-24'],
		['id' => 'broken', 'title' => 'No URL', 'updated' => '2026-02-01'],
	], [
		// The feed's settings live on the collection now; they used to ride
		// in the query string. The document is the same either way.
		'name'        => 'Example Blog',
		'description' => 'All the posts',
		'link'        => 'https://example.com/blog/',
		'image'       => 'https://example.com/logo.png',
		'language'    => 'en-US',
		'limit'       => 3,
		'content'     => 'summary',
	]);

	$xml = $builder->buildFeed('blog', ['rssurl' => 'https://example.com/feed/rss/blog']);

	expect(goldenRss($xml))->toMatchSnapshot();
});

test('a bare feed falls back to the site name and homepage', function (): void {
	$xml = goldenRssBuilder([['id' => 'one', 'title' => 'One', 'updated' => '2026-01-01']])->buildFeed('blog');

	expect(goldenRss($xml))->toMatchSnapshot();
});

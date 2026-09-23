<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * The `/feed/rss/{collection}` document, pinned. RssBuilder is now a mapper
 * onto FeedWriter; this snapshot is what proved the move changed one thing a
 * reader can see — a relative enclosure URL is now absolute, which RSS
 * requires anyway. The channel pubDate is the wall clock and is normalized.
 */
function goldenRssBuilder(array $objects): RssBuilder
{
	$collection         = test()->createMock(CollectionData::class);
	$collection->schema = 'blog';
	$schema             = test()->createMock(SchemaData::class);
	$schema->id         = 'blog';

	$collections = test()->createMock(CollectionFetcher::class);
	$collections->method('fetchCollection')->willReturn($collection);
	$schemas = test()->createMock(SchemaFetcher::class);
	$schemas->method('fetchSchema')->willReturn($schema);
	$index = test()->createMock(IndexFilter::class);
	$index->method('fetchFilteredIndex')->willReturn($objects);
	$urls = test()->createMock(ObjectUrlBuilder::class);
	$urls->method('buildUrl')->willReturnCallback(static fn (CollectionData $c, array $o): string => $o['id'] === 'broken' ? '' : '/blog/' . $o['id']);
	$urls->method('hasEmptySegments')->willReturn(false);

	$config         = test()->createMock(Config::class);
	$config->domain = 'example.com';
	$config->method('displayName')->willReturn('Example Site');

	return new RssBuilder($index, $collections, $urls, $schemas, $config, new FeedWriter($config));
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
	]);
	$builder->setFieldMap(['content' => 'summary']);

	$xml = $builder->buildFeed('blog', [
		'name'        => 'Example%20Blog',
		'description' => 'All%20the%20posts',
		'link'        => 'https://example.com/blog/',
		'rssurl'      => 'https://example.com/feed/rss/blog',
		'image'       => 'https://example.com/logo.png',
		'language'    => 'en-US',
		'limit'       => '3',
	]);

	expect(goldenRss($xml))->toMatchSnapshot();
});

test('a bare feed falls back to the site name and homepage', function (): void {
	$xml = goldenRssBuilder([['id' => 'one', 'title' => 'One', 'updated' => '2026-01-01']])->buildFeed('blog');

	expect(goldenRss($xml))->toMatchSnapshot();
});

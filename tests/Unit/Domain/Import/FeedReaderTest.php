<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Import\FeedReader;

// FeedReader turns an RSS/Atom document or a JSON Feed into one list of
// FeedEntry values. RssImporter used to run two parallel pipelines — an XML
// analyze/import pair and a JSON analyze/import pair — that re-implemented the
// same id / skip / map / download / queue sequence around format-specific
// extraction.
final class FeedReaderTest extends TestCase
{
	private function rss(string $items, string $channelExtra = ''): string
	{
		return '<?xml version="1.0"?><rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
			. '<title>The Feed</title><link>https://example.com</link><description>About it</description>' . $channelExtra
			. $items . '</channel></rss>';
	}

	public function testReadsAnRssDocument(): void
	{
		$doc = (new FeedReader())->parse($this->rss(
			'<item><title>A Post</title><link>https://example.com/a</link><author>ann@example.com (Ann)</author>'
			. '<category>News</category><category>PHP</category><pubDate>Tue, 01 Sep 2026 10:00:00 +0000</pubDate>'
			. '<description>Short</description><content:encoded xmlns:content="http://purl.org/rss/1.0/modules/content/"><![CDATA[<p>Long</p>]]></content:encoded>'
			. '<media:content url="https://example.com/a.jpg" medium="image"/></item>'
		));

		$this->assertSame('The Feed', $doc->title);
		$this->assertSame('https://example.com', $doc->link);
		$this->assertCount(1, $doc->entries);

		$entry = $doc->entries[0];
		$this->assertSame('A Post', $entry->title);
		$this->assertSame('https://example.com/a', $entry->link);
		$this->assertSame('Ann', $entry->author);
		$this->assertSame(['News', 'PHP'], $entry->categories);
		$this->assertStringStartsWith('2026-09-01T10:00:00', $entry->date);
		$this->assertSame('<p>Long</p>', $entry->content);
		$this->assertSame('Short', $entry->summary);
		$this->assertSame('https://example.com/a.jpg', $entry->imageUrl);
	}

	public function testAnRssItemWithOnlyADescriptionUsesItAsContent(): void
	{
		// Laminas answers getContent() with the description when there is no
		// content:encoded, so the record carries it as both body and summary —
		// the shape imports have always produced.
		$entry = (new FeedReader())->parse($this->rss('<item><title>T</title><description>Only this</description></item>'))->entries[0];

		$this->assertSame(['title' => 'T', 'link' => '', 'author' => '', 'categories' => [], 'date' => '', 'content' => 'Only this', 'summary' => 'Only this'], $entry->toRecord());
	}

	public function testASummaryAloneBecomesTheBody(): void
	{
		$entry = (new FeedReader())->parse(json_encode(['version' => 'https://jsonfeed.org/version/1.1', 'items' => [['title' => 'T', 'summary' => 'Only this']]]))->entries[0];

		$this->assertSame('Only this', $entry->toRecord()['content']);
		$this->assertArrayNotHasKey('summary', $entry->toRecord());
	}

	public function testReadsAJsonFeed(): void
	{
		$doc = (new FeedReader())->parse(json_encode([
			'version'       => 'https://jsonfeed.org/version/1.1',
			'title'         => 'JSON Feed',
			'home_page_url' => 'https://example.com',
			'authors'       => [['name' => 'Feed Author']],
			'items'         => [
				['id' => '1', 'title' => 'First', 'url' => 'https://example.com/1', 'content_html' => '<p>Body</p>', 'summary' => 'Sum', 'date_published' => '2026-09-01T10:00:00Z', 'tags' => ['a', 'b'], 'image' => 'https://example.com/1.jpg'],
				['id' => '2', 'content_text' => 'Plain', 'authors' => [['name' => 'Item Author']], 'banner_image' => 'https://example.com/2.jpg'],
			],
		]));

		$this->assertSame('JSON Feed', $doc->title);
		$this->assertCount(2, $doc->entries);

		[$first, $second] = $doc->entries;
		$this->assertSame('First', $first->title);
		$this->assertSame('Feed Author', $first->author, 'falls back to the feed-level author');
		$this->assertSame(['a', 'b'], $first->categories);
		$this->assertSame('<p>Body</p>', $first->content);
		$this->assertSame('Sum', $first->summary);
		$this->assertSame('https://example.com/1.jpg', $first->imageUrl);

		$this->assertSame('', $second->title);
		$this->assertSame('Untitled', $second->toRecord()['title'], 'the record falls back to Untitled');
		$this->assertSame('Item Author', $second->author);
		$this->assertSame('Plain', $second->content);
		$this->assertSame('https://example.com/2.jpg', $second->imageUrl);
	}

	public function testThePreviewRowSummarisesAndTruncates(): void
	{
		$entry = (new FeedReader())->parse($this->rss('<item><title>T</title><description>' . str_repeat('word ', 60) . '</description></item>'))->entries[0];
		$row   = $entry->preview();

		$this->assertSame('T', $row['title']);
		$this->assertSame(200, mb_strlen($row['summary']));
		$this->assertTrue($row['hasContent']);
		$this->assertFalse($row['hasImage']);
	}

	public function testAJsonDocumentThatIsNotAFeedIsParsedAsXmlAndRefused(): void
	{
		$this->expectException(\RuntimeException::class);
		(new FeedReader())->parse('{"not": "a feed"}');
	}
}

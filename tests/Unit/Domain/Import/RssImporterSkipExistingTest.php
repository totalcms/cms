<?php

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * Re-polling a feed regenerates the same slug ids for entries already imported.
 * Those duplicates must never reach the queue: ObjectSaver rejects them, so each
 * one becomes a failed job, and the image download that precedes queueing would
 * refetch and leak a temp file on every poll.
 */
final class RssImporterSkipExistingTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);
		$this->jobQueuer         = $this->createMock(JobQueuer::class);
		$this->httpClient        = $this->createMock(HttpClientInterface::class);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
	}

	private function importer(): RssImporter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new \Psr\Log\NullLogger());

		return new RssImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->jobQueuer,
			$this->httpClient,
			$loggerFactory
		);
	}

	private function xmlFeed(string ...$titles): string
	{
		$items = '';
		foreach ($titles as $title) {
			$items .= sprintf('<item><title>%s</title><link>https://example.com/%s</link></item>', $title, $title);
		}

		return '<?xml version="1.0"?><rss version="2.0"><channel>'
			. '<title>Feed</title><link>https://example.com</link><description>d</description>'
			. $items . '</channel></rss>';
	}

	/** @param array<int,array<string,mixed>> $items */
	private function jsonFeed(array $items): string
	{
		return (string)json_encode([
			'version' => 'https://jsonfeed.org/version/1.1',
			'title'   => 'Feed',
			'items'   => $items,
		]);
	}

	public function testSkipsXmlEntriesThatAlreadyExist(): void
	{
		$this->httpClient->method('request')
			->willReturn(new HttpResponse(200, $this->xmlFeed('Old Post', 'New Post')));

		$this->objectFetcher->method('existsObject')
			->willReturnCallback(fn (string $collection, string $id): bool => $id === 'old-post');

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('blog', $this->callback(fn (array $data): bool => $data['id'] === 'new-post'));

		$count = $this->importer()->import('https://example.com/feed', 'blog');

		$this->assertSame(1, $count, 'count reports queued entries only, skipping the duplicate');
	}

	public function testSkipsJsonEntriesThatAlreadyExist(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, $this->jsonFeed([
			['title' => 'Old Post', 'url' => 'https://example.com/old'],
			['title' => 'New Post', 'url' => 'https://example.com/new'],
		])));

		$this->objectFetcher->method('existsObject')
			->willReturnCallback(fn (string $collection, string $id): bool => $id === 'old-post');

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('blog', $this->callback(fn (array $data): bool => $data['id'] === 'new-post'));

		$count = $this->importer()->import('https://example.com/feed', 'blog');

		$this->assertSame(1, $count);
	}

	public function testReimportingAnUnchangedFeedQueuesNothing(): void
	{
		$this->httpClient->method('request')
			->willReturn(new HttpResponse(200, $this->xmlFeed('Old Post', 'Older Post')));

		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->jobQueuer->expects($this->never())->method('queueImport');

		$this->assertSame(0, $this->importer()->import('https://example.com/feed', 'blog'));
	}

	public function testDoesNotDownloadImagesForSkippedEntries(): void
	{
		$feed = '<?xml version="1.0"?><rss version="2.0"><channel>'
			. '<title>Feed</title><link>https://example.com</link><description>d</description>'
			. '<item><title>Old Post</title><link>https://example.com/old</link>'
			. '<enclosure url="https://example.com/image.jpg" type="image/jpeg" length="1"/></item>'
			. '</channel></rss>';

		$this->objectFetcher->method('existsObject')->willReturn(true);

		// Only the feed fetch itself — a second request would be the image
		// download for an entry that is never going to be queued.
		$this->httpClient->expects($this->once())
			->method('request')
			->willReturn(new HttpResponse(200, $feed));

		$this->importer()->import('https://example.com/feed', 'blog');
	}
}

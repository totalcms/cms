<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * Feed import runs unattended — from the admin once, then from a schedule — and
 * publishes into a live collection. The behaviours worth holding are the ones
 * whose failure is quiet: entries arriving published when they should be drafts,
 * a fetch failure that reads like an empty feed, and the preview disagreeing
 * with what the import will actually create.
 *
 * Sibling files cover duplicate skipping and the user-agent option.
 */
final class RssImporterBehaviourTest extends TestCase
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
		$this->objectFetcher->method('existsObject')->willReturn(false);
	}

	private function importer(): RssImporter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new RssImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->jobQueuer,
			$this->httpClient,
			$loggerFactory,
		);
	}

	private function serves(string $body, int $status = 200): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse($status, $body));
	}

	private function rss(string $itemXml): string
	{
		return '<?xml version="1.0"?><rss version="2.0"><channel>'
			. '<title>The Feed</title><link>https://example.com</link><description>About it</description>'
			. $itemXml . '</channel></rss>';
	}

	/** @return array<int,array<string,mixed>> the data queued for import */
	private function capture(callable $run): array
	{
		$queued = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queued): \TotalCMS\Domain\JobQueue\Data\JobData {
				$queued[] = $data;

				return new \TotalCMS\Domain\JobQueue\Data\JobData();
			});

		$run();

		return $queued;
	}

	// ── Draft handling ───────────────────────────────────────────────────────

	public function testEntriesArriveAsDraftsUnlessAskedOtherwise(): void
	{
		// The safe default, and the one that matters: an unattended feed import
		// that published straight to the live site would be discovered by the
		// site's readers rather than by its owner.
		$this->serves($this->rss('<item><title>A Post</title><link>https://example.com/a</link></item>'));

		$queued = $this->capture(fn () => $this->importer()->import('https://example.com/feed', 'blog'));

		$this->assertCount(1, $queued);
		$this->assertTrue($queued[0]['draft']);
	}

	public function testCanBeToldToPublishImmediately(): void
	{
		$this->serves($this->rss('<item><title>A Post</title><link>https://example.com/a</link></item>'));

		$queued = $this->capture(
			fn () => $this->importer()->import('https://example.com/feed', 'blog', ['draft' => false])
		);

		$this->assertFalse($queued[0]['draft']);
	}

	// ── Identity ─────────────────────────────────────────────────────────────

	public function testTheIdIsASlugOfTheTitle(): void
	{
		$this->serves($this->rss(
			'<item><title>Hello, World! Part 2</title><link>https://example.com/a</link></item>'
		));

		$queued = $this->capture(fn () => $this->importer()->import('https://example.com/feed', 'blog'));

		$this->assertSame('hello-world-part-2', $queued[0]['id']);
	}

	public function testAnEntryWithNoTitleStillImports(): void
	{
		// A feed with an untitled entry must not stop the import — the rest of
		// the feed is still wanted.
		$this->serves($this->rss(
			'<item><title></title><link>https://example.com/a</link></item>'
			. '<item><title>Real One</title><link>https://example.com/b</link></item>'
		));

		$queued = $this->capture(fn () => $this->importer()->import('https://example.com/feed', 'blog'));

		$this->assertCount(2, $queued);
		$this->assertSame('untitled', $queued[0]['id']);
	}

	// ── Field mapping ────────────────────────────────────────────────────────

	public function testMapsFeedFieldsOntoTheCollectionsOwnNames(): void
	{
		// A collection rarely uses the feed's field names, so this is the
		// difference between content landing in the right property and landing
		// nowhere.
		$this->serves($this->rss(
			'<item><title>A Post</title><link>https://example.com/a</link>'
			. '<description>The summary</description></item>'
		));

		$queued = $this->capture(fn () => $this->importer()->import(
			'https://example.com/feed',
			'blog',
			['fieldMap' => ['title' => 'headline', 'description' => 'standfirst']],
		));

		$this->assertArrayHasKey('headline', $queued[0]);
		$this->assertSame('A Post', $queued[0]['headline']);
		$this->assertArrayNotHasKey('title', $queued[0]);
	}

	// ── Failures ─────────────────────────────────────────────────────────────

	public function testRefusesToImportIntoACollectionThatDoesNotExist(): void
	{
		$fetcher = $this->createMock(CollectionFetcher::class);
		$fetcher->method('collectionExists')->willReturn(false);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		// And does not fetch the feed first — no point pulling a feed there is
		// nowhere to put.
		$this->httpClient->expects($this->never())->method('request');

		$importer = new RssImporter(
			$fetcher,
			$this->objectFetcher,
			$this->jobQueuer,
			$this->httpClient,
			$loggerFactory,
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('does not exist');

		$importer->import('https://example.com/feed', 'nope');
	}

	public function testAFailedFetchRaisesRatherThanImportingNothingQuietly(): void
	{
		// A 404 that returned 0 imported would look exactly like a feed with no
		// new entries, and a scheduled import would report success forever.
		$this->serves('Not Found', 404);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('HTTP 404');

		$this->importer()->import('https://example.com/feed', 'blog');
	}

	public function testATransportFailureNamesTheFeed(): void
	{
		$this->httpClient->method('request')->willThrowException(new \RuntimeException('connection refused'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('https://example.com/feed');

		$this->importer()->import('https://example.com/feed', 'blog');
	}

	// ── analyze(): the preview the operator decides from ─────────────────────

	public function testAnalyzeDescribesTheFeedWithoutImportingAnything(): void
	{
		$this->serves($this->rss(
			'<item><title>First</title><link>https://example.com/1</link></item>'
			. '<item><title>Second</title><link>https://example.com/2</link></item>'
		));

		$this->jobQueuer->expects($this->never())->method('queueImport');

		$result = $this->importer()->analyze('https://example.com/feed');

		$this->assertSame('The Feed', $result['feed']['title']);
		$this->assertCount(2, $result['entries']);
		$this->assertSame('First', $result['entries'][0]['title']);
	}

	public function testAnalyzeReadsAJsonFeedToo(): void
	{
		// Feed type is sniffed from the body rather than declared, so both
		// shapes have to reach the same preview structure.
		$this->serves((string)json_encode([
			'version' => 'https://jsonfeed.org/version/1.1',
			'title'   => 'JSON Feed',
			'items'   => [['title' => 'Only Post', 'url' => 'https://example.com/1']],
		]));

		$result = $this->importer()->analyze('https://example.com/feed.json');

		$this->assertSame('JSON Feed', $result['feed']['title']);
		$this->assertCount(1, $result['entries']);
		$this->assertSame('Only Post', $result['entries'][0]['title']);
	}
}

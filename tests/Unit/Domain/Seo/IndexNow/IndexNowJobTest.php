<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo\IndexNow;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Seo\IndexNow\IndexNowJob;
use TotalCMS\Domain\Seo\IndexNow\IndexNowOutbox;
use TotalCMS\Domain\Seo\IndexNow\IndexNowSubmitter;

/**
 * The job is the batching: one run, one take of the outbox, as few requests
 * as the 10,000-URL limit allows, and the unsent remainder restored when a
 * request must be retried.
 */
final class IndexNowJobTest extends TestCase
{
	private MockObject $submitter;
	private MockObject $outbox;
	private IndexNowJob $job;

	protected function setUp(): void
	{
		$this->submitter = $this->createMock(IndexNowSubmitter::class);
		$this->outbox    = $this->createMock(IndexNowOutbox::class);
		$this->job       = new IndexNowJob($this->submitter, $this->outbox);
	}

	private function jobData(): JobData
	{
		$job          = new JobData();
		$job->type    = JobData::TYPE_INDEXNOW;
		$job->payload = '[]';

		return $job;
	}

	public function testEverythingInTheOutboxGoesOutAsOneRequest(): void
	{
		$this->outbox->method('take')->willReturn(['https://example.com/a', 'https://example.com/b', 'https://example.com/c']);

		$this->submitter->expects($this->once())->method('submit')
			->with(['https://example.com/a', 'https://example.com/b', 'https://example.com/c']);
		$this->outbox->expects($this->never())->method('restore');

		$this->job->run($this->jobData());
	}

	public function testAnEmptyOutboxSendsNothing(): void
	{
		$this->outbox->method('take')->willReturn([]);
		$this->submitter->expects($this->never())->method('submit');

		$this->job->run($this->jobData());
	}

	public function testMoreThanTheProtocolLimitIsSplitIntoBatches(): void
	{
		$urls = array_map(static fn (int $i): string => "https://example.com/p{$i}", range(1, IndexNowSubmitter::MAX_URLS + 5));
		$this->outbox->method('take')->willReturn($urls);

		$sizes = [];
		$this->submitter->method('submit')->willReturnCallback(function (array $batch) use (&$sizes): bool {
			$sizes[] = count($batch);

			return true;
		});

		$this->job->run($this->jobData());

		$this->assertSame([IndexNowSubmitter::MAX_URLS, 5], $sizes);
	}

	public function testARetryableFailureRestoresTheUnsentUrlsAndRethrows(): void
	{
		$urls = array_map(static fn (int $i): string => "https://example.com/p{$i}", range(1, IndexNowSubmitter::MAX_URLS + 5));
		$this->outbox->method('take')->willReturn($urls);

		$calls = 0;
		$this->submitter->method('submit')->willReturnCallback(function () use (&$calls): bool {
			if (++$calls === 2) {
				throw new \RuntimeException('IndexNow returned HTTP 429');
			}

			return true;
		});

		// The first batch went; the second (5 URLs) is what must come back.
		$this->outbox->expects($this->once())->method('restore')->with(array_slice($urls, IndexNowSubmitter::MAX_URLS));

		$this->expectException(\RuntimeException::class);
		$this->job->run($this->jobData());
	}
}

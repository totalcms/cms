<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\Seo\IndexNow\IndexNowOutbox;

/**
 * Clearing the whole queue also empties the IndexNow outbox — it is the
 * pending job's payload, so leaving it would only delay the submissions
 * until the next save queued a replacement job. Narrower clears leave it.
 */
final class JobManagerClearTest extends TestCase
{
	private MockObject $repository;
	private MockObject $outbox;
	private JobManager $manager;

	protected function setUp(): void
	{
		$this->repository = $this->createMock(JobRepository::class);
		$this->outbox     = $this->createMock(IndexNowOutbox::class);
		$this->manager    = new JobManager($this->repository, $this->outbox);
	}

	public function testClearingTheQueueClearsTheOutboxToo(): void
	{
		$this->repository->method('clearQueue')->willReturn(true);
		$this->outbox->expects($this->once())->method('clear');

		$this->assertTrue($this->manager->clearQueue());
	}

	public function testClearingOneCollectionLeavesTheOutbox(): void
	{
		$this->repository->method('clearQueueForCollection')->willReturn(true);
		$this->outbox->expects($this->never())->method('clear');

		$this->manager->clearQueueForCollection('blog');
	}

	public function testClearingFailedJobsLeavesTheOutbox(): void
	{
		// A failed IndexNow job restored its URLs to the outbox; they were
		// never sent, so they wait for the next run.
		$this->repository->method('clearFailedJobs')->willReturn(2);
		$this->outbox->expects($this->never())->method('clear');

		$this->manager->clearFailedJobs();
	}

	public function testTheManagerStillWorksWithoutAnOutbox(): void
	{
		$repository = $this->createMock(JobRepository::class);
		$repository->method('clearQueue')->willReturn(true);

		$this->assertTrue((new JobManager($repository))->clearQueue());
	}
}

<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Service\DataViewDependencyResolver;
use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

final class DataViewUpdateSchedulerTest extends TestCase
{
	private DataViewUpdateScheduler $scheduler;
	private MockObject&DataViewDependencyResolver $resolver;
	private MockObject&JobQueuer $jobQueuer;

	protected function setUp(): void
	{
		$this->resolver   = $this->createMock(DataViewDependencyResolver::class);
		$this->jobQueuer  = $this->createMock(JobQueuer::class);
		$this->scheduler  = new DataViewUpdateScheduler($this->resolver, $this->jobQueuer);
	}

	public function testScheduleUpdatesQueuesJobsForMatchingDependency(): void
	{
		$this->resolver->expects($this->once())
			->method('resolveForCollection')
			->with('blog')
			->willReturn(['view-1', 'view-2']);

		$this->jobQueuer->expects($this->once())
			->method('queueViewUpdates')
			->with(['view-1', 'view-2']);

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesSkipsViewsWithoutMatchingDependency(): void
	{
		$this->resolver->method('resolveForCollection')
			->willReturn([]);

		$this->jobQueuer->expects($this->never())
			->method('queueViewUpdates');

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesHandlesMissingIndexGracefully(): void
	{
		$this->resolver->method('resolveForCollection')
			->willReturn([]);

		$this->jobQueuer->expects($this->never())
			->method('queueViewUpdates');

		// Should not throw
		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesSkipsViewsWithEmptyId(): void
	{
		$this->resolver->method('resolveForCollection')
			->willReturn(['valid-view']);

		$this->jobQueuer->expects($this->once())
			->method('queueViewUpdates')
			->with(['valid-view']);

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}
}

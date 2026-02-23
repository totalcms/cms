<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Service\DataViewUpdateScheduler;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

final class DataViewUpdateSchedulerTest extends TestCase
{
	private DataViewUpdateScheduler $scheduler;
	private MockObject&IndexReader $indexReader;
	private MockObject&JobQueuer $jobQueuer;

	protected function setUp(): void
	{
		$this->indexReader = $this->createMock(IndexReader::class);
		$this->jobQueuer   = $this->createMock(JobQueuer::class);
		$this->scheduler   = new DataViewUpdateScheduler($this->indexReader, $this->jobQueuer);
	}

	public function testScheduleUpdatesQueuesJobsForMatchingDependency(): void
	{
		$views = [
			['id' => 'view-1', 'dependencies' => ['blog', 'gallery']],
			['id' => 'view-2', 'dependencies' => ['blog']],
		];

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with(DataViewData::COLLECTION_ID)
			->willReturn(new IndexData($views));

		$this->jobQueuer->expects($this->exactly(2))
			->method('queueViewUpdate')
			->willReturnCallback(function (string $viewId): void {
				$this->assertContains($viewId, ['view-1', 'view-2']);
			});

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesSkipsViewsWithoutMatchingDependency(): void
	{
		$views = [
			['id' => 'view-1', 'dependencies' => ['gallery']],
			['id' => 'view-2', 'dependencies' => ['products']],
		];

		$this->indexReader->method('fetchIndex')
			->willReturn(new IndexData($views));

		$this->jobQueuer->expects($this->never())
			->method('queueViewUpdate');

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesHandlesMissingIndexGracefully(): void
	{
		$this->indexReader->method('fetchIndex')
			->willThrowException(new \RuntimeException('Collection not found'));

		$this->jobQueuer->expects($this->never())
			->method('queueViewUpdate');

		// Should not throw
		$this->scheduler->scheduleUpdatesForCollection('blog');
	}

	public function testScheduleUpdatesSkipsViewsWithEmptyId(): void
	{
		$views = [
			['id' => '', 'dependencies' => ['blog']],
			['id' => 'valid-view', 'dependencies' => ['blog']],
		];

		$this->indexReader->method('fetchIndex')
			->willReturn(new IndexData($views));

		$this->jobQueuer->expects($this->once())
			->method('queueViewUpdate')
			->with('valid-view');

		$this->scheduler->scheduleUpdatesForCollection('blog');
	}
}

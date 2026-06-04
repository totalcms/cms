<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

final class QueueViewUpdatesTest extends TestCase
{
	public function testEnqueuesInOrderAfterClearingExisting(): void
	{
		$repo = $this->createMock(JobRepository::class);

		$deleted = [];
		$repo->method('deletePendingJob')->willReturnCallback(
			function (string $type, string $collection, string $payload) use (&$deleted): void {
				$deleted[] = $payload;
			}
		);

		$queued = [];
		$repo->method('queueJob')->willReturnCallback(
			function (string $type, string $collection, string $payload) use (&$queued): JobData {
				$queued[] = $payload;
				// Return value is ignored by queueViewUpdates; build without ctor.
				return (new \ReflectionClass(JobData::class))->newInstanceWithoutConstructor();
			}
		);

		$queuer = new JobQueuer($repo);
		$queuer->queueViewUpdates(['A', 'B', 'C']);

		$expectedPayloads = [
			json_encode(['viewId' => 'A'], JSON_THROW_ON_ERROR),
			json_encode(['viewId' => 'B'], JSON_THROW_ON_ERROR),
			json_encode(['viewId' => 'C'], JSON_THROW_ON_ERROR),
		];

		$this->assertSame($expectedPayloads, $deleted, 'pending dupes cleared for each id');
		$this->assertSame($expectedPayloads, $queued, 'enqueued in given order');
	}

	public function testEmptyListIsNoop(): void
	{
		$repo = $this->createMock(JobRepository::class);
		$repo->expects($this->never())->method('queueJob');

		(new JobQueuer($repo))->queueViewUpdates([]);
	}
}

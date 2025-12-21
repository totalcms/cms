<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

final class JobRunnerTest extends TestCase
{
	private JobRunner $jobRunner;
	private \PHPUnit\Framework\MockObject\MockObject $jobRepository;
	private \PHPUnit\Framework\MockObject\MockObject $objectImporter;
	private \PHPUnit\Framework\MockObject\MockObject $objectExporter;
	private \PHPUnit\Framework\MockObject\MockObject $indexBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $factoryImporter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	/**
	 * Create a properly initialized JobData for testing.
	 *
	 * @param array<string,string> $overrides
	 */
	private function createJob(string $type, string $collection, string $payload = '', array $overrides = []): JobData
	{
		return JobData::fromArray(array_merge([
			'id'         => uniqid('job-'),
			'type'       => $type,
			'collection' => $collection,
			'payload'    => $payload,
			'status'     => JobData::STATUS_PENDING,
			'attempts'   => '0',
			'createdAt'  => date('Y-m-d H:i:s'),
			'updatedAt'  => date('Y-m-d H:i:s'),
			'lastError'  => '',
		], $overrides));
	}

	protected function setUp(): void
	{
		$this->jobRepository        = $this->createMock(JobRepository::class);
		$this->objectImporter       = $this->createMock(ObjectImporter::class);
		$this->objectExporter       = $this->createMock(ObjectExporter::class);
		$this->indexBuilder         = $this->createMock(IndexBuilder::class);
		$this->factoryImporter      = $this->createMock(FactoryImporter::class);
		$this->collectionRepository = $this->createMock(CollectionRepository::class);
		$this->loggerFactory        = $this->createMock(LoggerFactory::class);

		// Set up logger factory to return itself for chaining and create a null logger
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->jobRunner = new JobRunner(
			$this->jobRepository,
			$this->objectImporter,
			$this->objectExporter,
			$this->indexBuilder,
			$this->factoryImporter,
			$this->collectionRepository,
			$this->loggerFactory,
		);
	}

	public function testProcessPendingJobsEnablesQueueRebuildForImportJobs(): void
	{
		// Create a mock collection with queueRebuildOnSave = false
		$collection                     = new CollectionData();
		$collection->id                 = 'test-collection';
		$collection->queueRebuildOnSave = false;

		// Create a pending import job
		$payload   = json_encode(['id' => 'test-obj', 'title' => 'Test']) ?: '';
		$importJob = $this->createJob(JobData::TYPE_IMPORT, 'test-collection', $payload);

		// Set up job repository to return the pending job, then no more jobs
		$this->jobRepository
			->expects($this->once())
			->method('fetchPendingJobs')
			->willReturn([$importJob]);

		$this->jobRepository
			->expects($this->exactly(2))
			->method('hasPendingJobs')
			->willReturnOnConsecutiveCalls(true, false);

		$this->jobRepository
			->expects($this->once())
			->method('fetchNextJob')
			->willReturn($importJob);

		$this->jobRepository
			->expects($this->once())
			->method('delete')
			->with($importJob);

		// Collection repository should fetch once (during enable phase)
		// and save twice (enable + restore)
		$this->collectionRepository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collection);

		$savedCollections = [];
		$this->collectionRepository
			->expects($this->exactly(2))
			->method('saveCollection')
			->willReturnCallback(function (CollectionData $c) use (&$savedCollections): void {
				$savedCollections[] = $c->queueRebuildOnSave;
			});

		// Object importer should be called once
		$this->objectImporter
			->expects($this->once())
			->method('importObject')
			->with('test-collection', ['id' => 'test-obj', 'title' => 'Test']);

		// Index should be rebuilt once at the end
		$this->indexBuilder
			->expects($this->once())
			->method('buildIndex')
			->with('test-collection')
			->willReturn(new IndexData());

		// Execute
		$this->jobRunner->processPendingJobs();

		// Verify collection was saved with queueRebuildOnSave = true first, then false
		expect($savedCollections)->toBe([true, false]);
	}

	public function testProcessPendingJobsSkipsCollectionAlreadyWithQueueRebuildEnabled(): void
	{
		// Create a mock collection with queueRebuildOnSave = true (already enabled)
		$collection                     = new CollectionData();
		$collection->id                 = 'optimized-collection';
		$collection->queueRebuildOnSave = true;

		// Create a pending import job
		$payload   = json_encode(['id' => 'test-obj']) ?: '';
		$importJob = $this->createJob(JobData::TYPE_IMPORT, 'optimized-collection', $payload);

		$this->jobRepository
			->expects($this->once())
			->method('fetchPendingJobs')
			->willReturn([$importJob]);

		$this->jobRepository
			->expects($this->exactly(2))
			->method('hasPendingJobs')
			->willReturnOnConsecutiveCalls(true, false);

		$this->jobRepository
			->expects($this->once())
			->method('fetchNextJob')
			->willReturn($importJob);

		$this->jobRepository
			->expects($this->once())
			->method('delete');

		// Collection should be fetched once to check queueRebuildOnSave
		$this->collectionRepository
			->expects($this->once())
			->method('fetchCollection')
			->with('optimized-collection')
			->willReturn($collection);

		// Collection should NOT be saved since it's already optimized
		$this->collectionRepository
			->expects($this->never())
			->method('saveCollection');

		// Index should NOT be rebuilt by finalizeOptimizedCollections
		// (since we didn't temporarily enable queueRebuildOnSave)
		$this->indexBuilder
			->expects($this->never())
			->method('buildIndex');

		$this->objectImporter
			->expects($this->once())
			->method('importObject');

		// Execute
		$this->jobRunner->processPendingJobs();
	}

	public function testProcessPendingJobsHandlesMultipleCollections(): void
	{
		// Create two collections
		$collection1                     = new CollectionData();
		$collection1->id                 = 'collection-1';
		$collection1->queueRebuildOnSave = false;

		$collection2                     = new CollectionData();
		$collection2->id                 = 'collection-2';
		$collection2->queueRebuildOnSave = false;

		// Create import jobs for both collections
		$payload1 = json_encode(['id' => 'obj-1']) ?: '';
		$job1     = $this->createJob(JobData::TYPE_IMPORT, 'collection-1', $payload1);

		$payload2 = json_encode(['id' => 'obj-2']) ?: '';
		$job2     = $this->createJob(JobData::TYPE_IMPORT, 'collection-2', $payload2);

		$this->jobRepository
			->expects($this->once())
			->method('fetchPendingJobs')
			->willReturn([$job1, $job2]);

		$this->jobRepository
			->expects($this->exactly(3))
			->method('hasPendingJobs')
			->willReturnOnConsecutiveCalls(true, true, false);

		$this->jobRepository
			->expects($this->exactly(2))
			->method('fetchNextJob')
			->willReturnOnConsecutiveCalls($job1, $job2);

		$this->jobRepository
			->expects($this->exactly(2))
			->method('delete');

		// Each collection fetched once (enable phase only)
		$this->collectionRepository
			->expects($this->exactly(2))
			->method('fetchCollection')
			->willReturnCallback(fn ($id): CollectionData => $id === 'collection-1' ? $collection1 : $collection2);

		// Each collection saved twice (enable + restore)
		$this->collectionRepository
			->expects($this->exactly(4))
			->method('saveCollection');

		// Both indexes should be rebuilt
		$rebuiltCollections = [];
		$this->indexBuilder
			->expects($this->exactly(2))
			->method('buildIndex')
			->willReturnCallback(function ($id) use (&$rebuiltCollections): IndexData {
				$rebuiltCollections[] = $id;

				return new IndexData();
			});

		$this->objectImporter
			->expects($this->exactly(2))
			->method('importObject');

		// Execute
		$this->jobRunner->processPendingJobs();

		// Verify both collections had their indexes rebuilt
		expect($rebuiltCollections)->toContain('collection-1');
		expect($rebuiltCollections)->toContain('collection-2');
	}

	public function testProcessPendingJobsIgnoresNonImportJobs(): void
	{
		// Create a collection
		$collection                     = new CollectionData();
		$collection->id                 = 'test-collection';
		$collection->queueRebuildOnSave = false;

		// Create a rebuild job (not import/update/factory)
		$rebuildJob = $this->createJob(JobData::TYPE_REBUILD, 'test-collection', '');

		$this->jobRepository
			->expects($this->once())
			->method('fetchPendingJobs')
			->willReturn([$rebuildJob]);

		$this->jobRepository
			->expects($this->exactly(2))
			->method('hasPendingJobs')
			->willReturnOnConsecutiveCalls(true, false);

		$this->jobRepository
			->expects($this->once())
			->method('fetchNextJob')
			->willReturn($rebuildJob);

		$this->jobRepository
			->expects($this->once())
			->method('delete');

		// Collection should NOT be fetched for non-import jobs
		$this->collectionRepository
			->expects($this->never())
			->method('fetchCollection');

		$this->collectionRepository
			->expects($this->never())
			->method('saveCollection');

		// Index builder will be called directly by processRebuildJob
		$this->indexBuilder
			->expects($this->once())
			->method('buildIndex')
			->with('test-collection')
			->willReturn(new IndexData());

		// Execute
		$this->jobRunner->processPendingJobs();
	}
}

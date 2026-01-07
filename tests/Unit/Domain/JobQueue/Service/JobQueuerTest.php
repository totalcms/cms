<?php

namespace Tests\Unit\Domain\JobQueue\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

final class JobQueuerTest extends TestCase
{
	private JobQueuer $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $jobRepository;

	protected function setUp(): void
	{
		$this->jobRepository = $this->createMock(JobRepository::class);
		$this->jobQueuer     = new JobQueuer($this->jobRepository);
	}

	public function testQueueJobCreatesJobWithCorrectType(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => 'custom',
			'collection' => 'blog',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"key":"value"}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with('custom', 'blog', '{"key":"value"}')
			->willReturn($expectedJob);

		$result = $this->jobQueuer->queueJob('custom', 'blog', ['key' => 'value']);

		$this->assertSame($expectedJob, $result);
	}

	public function testQueueImportCreatesImportJob(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => JobData::TYPE_IMPORT,
			'collection' => 'users',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"file":"data.csv"}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with(JobData::TYPE_IMPORT, 'users', '{"file":"data.csv"}')
			->willReturn($expectedJob);

		$result = $this->jobQueuer->queueImport('users', ['file' => 'data.csv']);

		$this->assertSame($expectedJob, $result);
	}

	public function testQueueUpdateCreatesUpdateJob(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => JobData::TYPE_UPDATE,
			'collection' => 'products',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"field":"price"}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with(JobData::TYPE_UPDATE, 'products', '{"field":"price"}')
			->willReturn($expectedJob);

		$result = $this->jobQueuer->queueUpdate('products', ['field' => 'price']);

		$this->assertSame($expectedJob, $result);
	}

	public function testQueueExportCreatesExportJob(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => JobData::TYPE_EXPORT,
			'collection' => 'orders',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"format":"csv"}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with(JobData::TYPE_EXPORT, 'orders', '{"format":"csv"}')
			->willReturn($expectedJob);

		$result = $this->jobQueuer->queueExport('orders', ['format' => 'csv']);

		$this->assertSame($expectedJob, $result);
	}

	public function testQueueBuildIndexSkipsIfAlreadyQueued(): void
	{
		$this->jobRepository->expects($this->once())
			->method('hasReindexQueuedFromCollection')
			->with('blog')
			->willReturn(true);

		$this->jobRepository->expects($this->never())
			->method('queueJob');

		$this->jobQueuer->queueBuildIndex('blog');
	}

	public function testQueueBuildIndexCreatesJobIfNotQueued(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => JobData::TYPE_REBUILD,
			'collection' => 'blog',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '[]',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('hasReindexQueuedFromCollection')
			->with('blog')
			->willReturn(false);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with(JobData::TYPE_REBUILD, 'blog', '[]')
			->willReturn($expectedJob);

		$this->jobQueuer->queueBuildIndex('blog');
	}

	public function testQueueFactoryCreatesFactoryJob(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'job-factory-123',
			'type'       => JobData::TYPE_FACTORY,
			'collection' => 'products',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"quantity":10,"rules":{"title":"faker.sentence"}}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with(
				JobData::TYPE_FACTORY,
				'products',
				$this->callback(function ($payload): bool {
					$data = json_decode($payload, true);

					return $data['quantity'] === 10 && isset($data['rules']['title']);
				})
			)
			->willReturn($expectedJob);

		$result = $this->jobQueuer->queueFactory('products', 10, ['title' => 'faker.sentence']);

		$this->assertSame('job-factory-123', $result);
	}

	public function testQueueFactoryReturnsJobId(): void
	{
		$expectedJob = JobData::fromArray([
			'id'         => 'unique-job-id',
			'type'       => JobData::TYPE_FACTORY,
			'collection' => 'users',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => '{"quantity":5,"rules":[]}',
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->willReturn($expectedJob);

		$jobId = $this->jobQueuer->queueFactory('users', 5);

		$this->assertSame('unique-job-id', $jobId);
	}

	public function testQueueJobEncodesDataAsJson(): void
	{
		$complexData = [
			'nested' => ['key' => 'value'],
			'array'  => [1, 2, 3],
			'bool'   => true,
			'null'   => null,
		];

		$expectedPayload = json_encode($complexData, JSON_THROW_ON_ERROR);

		$expectedJob = JobData::fromArray([
			'id'         => 'job-123',
			'type'       => 'custom',
			'collection' => 'test',
			'status'     => JobData::STATUS_PENDING,
			'payload'    => $expectedPayload,
			'createdAt'  => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		$this->jobRepository->expects($this->once())
			->method('queueJob')
			->with('custom', 'test', $expectedPayload)
			->willReturn($expectedJob);

		$this->jobQueuer->queueJob('custom', 'test', $complexData);
	}
}

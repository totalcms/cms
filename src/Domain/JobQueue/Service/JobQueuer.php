<?php

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Data\JobData;

final class JobQueuer
{
	public function __construct(
		private JobRepository $jobRepository,
	) {}

	/** @param array<mixed> $data */
	public function queueJob(string $type, string $collection, array $data = []): JobData
	{
		$payload = json_encode($data, JSON_THROW_ON_ERROR);
		return $this->jobRepository->queueJob($type, $collection, $payload);
	}

	/** @param array<mixed> $data */
	public function queueImport(string $collection, array $data): JobData
	{
		return $this->queueJob(JobData::TYPE_IMPORT, $collection, $data);
	}

	/** @param array<mixed> $data */
	public function queueUpdate(string $collection, array $data): JobData
	{
		return $this->queueJob(JobData::TYPE_UPDATE, $collection, $data);
	}

	/** @param array<mixed> $data */
	public function queueExport(string $collection, array $data): JobData
	{
		return $this->queueJob(JobData::TYPE_EXPORT, $collection, $data);
	}

	public function queueRebuildIndex(string $collection): JobData
	{
		return $this->queueJob(JobData::TYPE_REBUILD, $collection);
	}
}

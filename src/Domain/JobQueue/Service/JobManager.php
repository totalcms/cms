<?php

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Repository\JobRepository;

final class JobManager
{
	public function __construct(
		private JobRepository $jobRepository,
	) {}

	/** @return array<string,int>  */
	public function queueStats(): array
	{
		return $this->jobRepository->queueStats();
	}

	/** @return array<string,int>  */
	public function queueStatsForCollection(string $collection): array
	{
		return $this->jobRepository->queueStatsForCollection($collection);
	}

	public function clearQueue(): bool
	{
		return $this->jobRepository->clearQueue();
	}

	public function clearQueueForCollection(string $collection): bool
	{
		return $this->jobRepository->clearQueueForCollection($collection);
	}
}

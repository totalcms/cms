<?php

namespace TotalCMS\Domain\JobQueue\Service;

use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;

readonly class JobManager
{
	public function __construct(
		private JobRepository $jobRepository,
	) {
	}

	/** @return array<string,int>  */
	public function queueByType(): array
	{
		return $this->jobRepository->queueByType();
	}

	/** @return array<string,int>  */
	public function queueByTypeForCollection(string $collection): array
	{
		return $this->jobRepository->queueByTypeForCollection($collection);
	}

	/** @return array<string,int>  */
	public function queueByStatus(): array
	{
		return $this->jobRepository->queueByStatus();
	}

	/** @return array<string,int>  */
	public function queueByStatusForCollection(string $collection): array
	{
		return $this->jobRepository->queueByStatusForCollection($collection);
	}

	/** @return array<string,array<string,int>>  */
	public function queueStats(): array
	{
		return [
			'status' => $this->queueByStatus(),
			'type'   => $this->queueByType(),
		];
	}

	/** @return array<string,array<string,int>>  */
	public function queueStatsForCollection(string $collection): array
	{
		return [
			'status' => $this->queueByStatusForCollection($collection),
			'type'   => $this->queueByTypeForCollection($collection),
		];
	}

	public function clearQueue(): bool
	{
		return $this->jobRepository->clearQueue();
	}

	public function clearQueueForCollection(string $collection): bool
	{
		return $this->jobRepository->clearQueueForCollection($collection);
	}

	/**
	 * @return array<JobData>
	 */
	public function getPendingJobs(?int $limit = null): array
	{
		return $this->jobRepository->fetchPendingJobs($limit);
	}

	/**
	 * @return array<JobData>
	 */
	public function getFailedJobs(?int $limit = null): array
	{
		return $this->jobRepository->fetchFailedJobs($limit);
	}

	/**
	 * Get diagnostic info about the database for debugging.
	 *
	 * @return array{path: string, exists: bool, datadir: string}
	 */
	public function getDatabaseInfo(): array
	{
		return $this->jobRepository->getDatabaseInfo();
	}

	/**
	 * Get raw job count directly from database for debugging.
	 *
	 * @return array{total: int, pendingJobs: int, allStatuses: array<string,int>}
	 */
	public function getRawJobCount(): array
	{
		return $this->jobRepository->getRawJobCount();
	}
}

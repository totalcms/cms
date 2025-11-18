<?php

namespace TotalCMS\Domain\JobQueue\Repository;

use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Support\Config;

/** @SuppressWarnings("PHPMD.TooManyPublicMethods") */
class JobRepository
{
	private ?\PDO $db = null;

	public function __construct(private readonly Config $config)
	{
	}

	private function getDbPath(): string
	{
		return $this->config->datadir . '/.system/jobqueue';
	}

	private function dbExists(): bool
	{
		return file_exists($this->getDbPath());
	}

	/**
	 * Lazy-load database connection - only creates the database when first needed.
	 * This prevents unnecessary file creation during setup or when job queue is not used.
	 *
	 * @return \PDO
	 */
	private function getDb(): \PDO
	{
		if ($this->db !== null) {
			return $this->db;
		}

		$dbPath = $this->getDbPath();
		$exists = $this->dbExists();

		// Ensure directory exists before creating database
		$dir = dirname($dbPath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$this->db = new \PDO('sqlite:' . $dbPath);

		if (!$exists) {
			$this->getDb()->exec(<<<SQL
				CREATE TABLE jobqueue (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					type TEXT NOT NULL,
					payload TEXT NOT NULL,
					collection TEXT NOT NULL,
					status TEXT DEFAULT 'pending',
					attempts INTEGER DEFAULT 0,
					lastError TEXT DEFAULT NULL,
					createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
					updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP
				);
			SQL);
		}

		return $this->db;
	}

	private function markInProgress(JobData $job): JobData
	{
		$job->attempts++;

		return $this->updateJobStatus($job, JobData::STATUS_IN_PROGRESS);
	}

	private function updateJobStatus(JobData $job, string $status): JobData
	{
		if (!in_array($status, JobData::STATUS_LIST)) {
			throw new \DomainException(sprintf('Invalid job status %s', $status));
		}
		$job->status = $status;

		$sql = <<<SQL
			UPDATE jobqueue
			SET status = :status,
				updatedAt = CURRENT_TIMESTAMP,
				attempts = :attempts,
				lastError = :lastError
			WHERE id = :id
		SQL;
		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':id', $job->id);
		$stmt->bindValue(':status', $job->status);
		$stmt->bindValue(':attempts', $job->attempts);
		$stmt->bindValue(':lastError', $job->lastError);
		$stmt->execute();

		return $job;
	}

	public function fetchNextJob(): JobData
	{
		$sql = <<<SQL
			SELECT * FROM jobqueue
			WHERE status = 'pending'
			ORDER BY id ASC
			LIMIT 1
		SQL;
		$stmt = $this->getDb()->prepare($sql);
		$stmt->execute();

		if (!$stmt) {
			throw new \RuntimeException('Failed to prepare query to fetch next job');
		}
		$record = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!$record) {
			throw new \DomainException('No pending jobs found');
		}

		$job = JobData::fromArray($record);

		$job = $this->markInProgress($job);

		return $job;
	}

	public function hasReindexQueuedFromCollection(string $collection): bool
	{
		$stmt = $this->getDb()->prepare("SELECT * FROM jobqueue WHERE status = 'pending' and collection = :collection LIMIT 1");
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$record = $stmt->fetch(\PDO::FETCH_ASSOC);

		return !empty($record);
	}

	public function hasPendingJobs(): bool
	{
		$stmt = $this->getDb()->prepare("SELECT * FROM jobqueue WHERE status = 'pending' LIMIT 1");
		$stmt->execute();
		$record = $stmt->fetch(\PDO::FETCH_ASSOC);

		return !empty($record);
	}

	public function delete(JobData $job): bool
	{
		$stmt = $this->getDb()->prepare('DELETE FROM jobqueue WHERE id = :id');
		$stmt->bindValue(':id', $job->id);

		return $stmt->execute();
	}

	public function markFailed(JobData $job, string $error): JobData
	{
		$job->lastError = $error;

		return $this->updateJobStatus($job, JobData::STATUS_FAILED);
	}

	public function fetchJobById(int $id): JobData
	{
		$stmt = $this->getDb()->prepare('SELECT * FROM jobqueue WHERE id = :id');
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		$record = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!$record) {
			throw new \DomainException(sprintf('Job with ID %d not found', $id));
		}

		return JobData::fromArray($record);
	}

	public function queueJob(string $type, string $collection, string $payload = ''): JobData
	{
		if (!in_array($type, JobData::TYPE_LIST)) {
			throw new \DomainException(sprintf('Invalid job type %s', $type));
		}
		$sql = <<<SQL
			INSERT INTO jobqueue (type, payload, collection)
			VALUES (:type, :payload, :collection)
		SQL;
		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':type', $type);
		$stmt->bindValue(':payload', $payload);
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();

		$id = $this->getDb()->lastInsertId();
		if (!$id) {
			throw new \DomainException('Failed to insert job into the queue');
		}

		return $this->fetchJobById(intval($id));
	}

	/** @return array<string,int>  */
	public function queueByType(): array
	{
		$results = [];

		foreach (JobData::TYPE_LIST as $type) {
			$stmt = $this->getDb()->prepare('SELECT COUNT(*) as type FROM jobqueue WHERE type = :type');
			$stmt->bindValue(':type', $type);
			$stmt->execute();
			$results[ucfirst($type)] = $stmt ? intval($stmt->fetchColumn()) : 0;
		}
		ksort($results);

		// $stmt  = $this->getDb()->query('SELECT COUNT(*) as total FROM jobqueue');
		// $total = $stmt ? intval($stmt->fetchColumn()) : 0;
		// $results['Total'] = $total;

		return $results;
	}

	/** @return array<string,int>  */
	public function queueByStatus(): array
	{
		$stmt  = $this->getDb()->query('SELECT COUNT(*) as total FROM jobqueue');
		$total = $stmt ? intval($stmt->fetchColumn()) : 0;

		$results = [];

		foreach (JobData::STATUS_LIST as $status) {
			$stmt = $this->getDb()->prepare('SELECT COUNT(*) as status FROM jobqueue WHERE status = :status');
			$stmt->bindValue(':status', $status);
			$stmt->execute();
			$results[$status] = $stmt ? intval($stmt->fetchColumn()) : 0;
		}

		// I want these to be in a specific order
		return [
			'Pending'     => $results[JobData::STATUS_PENDING] ?? 0,
			'In-Progress' => $results[JobData::STATUS_IN_PROGRESS] ?? 0,
			'Failed'      => $results[JobData::STATUS_FAILED] ?? 0,
			'Total'       => $total,
		];
	}

	/** @return array<string,int>  */
	public function queueByTypeForCollection(string $collection): array
	{
		$results = [];

		foreach (JobData::TYPE_LIST as $type) {
			$stmt = $this->getDb()->prepare('SELECT COUNT(*) as type FROM jobqueue WHERE type = :type AND collection = :collection');
			$stmt->bindValue(':type', $type);
			$stmt->bindValue(':collection', $collection);
			$stmt->execute();
			$results[ucfirst($type)] = $stmt ? intval($stmt->fetchColumn()) : 0;
		}
		ksort($results);

		// $stmt  = $this->getDb()->prepare('SELECT COUNT(*) as total FROM jobqueue WHERE collection = :collection');
		// $stmt->bindValue(':collection', $collection);
		// $total = $stmt ? intval($stmt->fetchColumn()) : 0;
		// $results['Total'] = $total;

		return $results;
	}

	/** @return array<string,int>  */
	public function queueByStatusForCollection(string $collection): array
	{
		$stmt = $this->getDb()->prepare('SELECT COUNT(*) as total FROM jobqueue WHERE collection = :collection');
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$total = $stmt ? intval($stmt->fetchColumn()) : 0;

		$results = [];

		foreach (JobData::STATUS_LIST as $status) {
			$stmt = $this->getDb()->prepare('SELECT COUNT(*) as status FROM jobqueue WHERE status = :status AND collection = :collection');
			$stmt->bindValue(':status', $status);
			$stmt->bindValue(':collection', $collection);
			$stmt->execute();
			$results[$status] = $stmt ? intval($stmt->fetchColumn()) : 0;
		}

		// I want these to be in a specific order
		return [
			'Pending'     => $results[JobData::STATUS_PENDING] ?? 0,
			'In-Progress' => $results[JobData::STATUS_IN_PROGRESS] ?? 0,
			'Failed'      => $results[JobData::STATUS_FAILED] ?? 0,
			'Total'       => $total,
		];
	}

	public function clearQueue(): bool
	{
		$stmt = $this->getDb()->prepare('DELETE FROM jobqueue');

		return $stmt->execute();
	}

	public function clearQueueForCollection(string $collection): bool
	{
		$stmt = $this->getDb()->prepare('DELETE FROM jobqueue WHERE collection = :collection');
		$stmt->bindValue(':collection', $collection);

		return $stmt->execute();
	}

	/**
	 * Fetch all pending jobs.
	 *
	 * @return array<JobData>
	 */
	public function fetchPendingJobs(?int $limit = null): array
	{
		$sql = <<<SQL
			SELECT * FROM jobqueue
			WHERE status = :status
			ORDER BY id DESC
		SQL;

		if ($limit !== null) {
			$sql .= ' LIMIT :limit';
		}

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':status', JobData::STATUS_PENDING);
		if ($limit !== null) {
			$stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
		}
		$stmt->execute();

		$jobs = [];
		while ($record = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$jobs[] = JobData::fromArray($record);
		}

		return $jobs;
	}

	/**
	 * Fetch all failed jobs.
	 *
	 * @return array<JobData>
	 */
	public function fetchFailedJobs(?int $limit = null): array
	{
		$sql = <<<SQL
			SELECT * FROM jobqueue
			WHERE status = :status
			ORDER BY id DESC
		SQL;

		if ($limit !== null) {
			$sql .= ' LIMIT :limit';
		}

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':status', JobData::STATUS_FAILED);
		if ($limit !== null) {
			$stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
		}
		$stmt->execute();

		$jobs = [];
		while ($record = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$jobs[] = JobData::fromArray($record);
		}

		return $jobs;
	}

	/**
	 * Reset a job's status to pending for retry.
	 */
	public function resetJobStatus(JobData $job): JobData
	{
		$job->status    = JobData::STATUS_PENDING;
		$job->lastError = '';
		// Keep the attempt count to prevent infinite retries

		return $this->updateJobStatus($job, JobData::STATUS_PENDING);
	}
}

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
	 * Get diagnostic info about the database for debugging.
	 *
	 * @return array{path: string, exists: bool, datadir: string}
	 */
	public function getDatabaseInfo(): array
	{
		return [
			'path'    => $this->getDbPath(),
			'exists'  => $this->dbExists(),
			'datadir' => $this->config->datadir,
		];
	}

	/**
	 * Get raw job count directly from database for debugging.
	 * Uses simple queries to verify database contents.
	 *
	 * @return array{total: int, pendingJobs: int, allStatuses: array<string,int>}
	 */
	public function getRawJobCount(): array
	{
		if (!$this->dbExists()) {
			return [
				'total'       => 0,
				'pendingJobs' => 0,
				'allStatuses' => [],
			];
		}

		// Simple count of all rows
		$stmt  = $this->getDb()->query('SELECT COUNT(*) FROM jobqueue');
		$total = $stmt ? intval($stmt->fetchColumn()) : 0;

		// Count pending using fetchPendingJobs approach (SELECT *)
		$pending = count($this->fetchPendingJobs());

		// Get all distinct status values and their counts
		$stmt       = $this->getDb()->query('SELECT status, COUNT(*) as cnt FROM jobqueue GROUP BY status');
		$allStatuses = [];
		if ($stmt) {
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$allStatuses[(string)$row['status']] = intval($row['cnt']);
			}
		}

		return [
			'total'       => $total,
			'pendingJobs' => $pending,
			'allStatuses' => $allStatuses,
		];
	}

	/**
	 * Lazy-load database connection - only creates the database when first needed.
	 * This prevents unnecessary file creation during setup or when job queue is not used.
	 */
	private function getDb(): \PDO
	{
		if ($this->db instanceof \PDO) {
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
		// Return zeros if database doesn't exist - don't create empty database
		if (!$this->dbExists()) {
			$results = [];
			foreach (JobData::TYPE_LIST as $type) {
				$results[ucfirst($type)] = 0;
			}
			ksort($results);

			return $results;
		}

		// Use a single query with GROUP BY for efficiency
		$sql = <<<SQL
			SELECT type, COUNT(*) as count
			FROM jobqueue
			GROUP BY type
		SQL;

		$stmt = $this->getDb()->query($sql);

		// Initialize all type counts to 0
		$counts = [];
		foreach (JobData::TYPE_LIST as $type) {
			$counts[$type] = 0;
		}

		if ($stmt) {
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$type  = $row['type'] ?? '';
				$count = intval($row['count'] ?? 0);
				if (isset($counts[$type])) {
					$counts[$type] = $count;
				}
			}
		}

		// Convert to display-friendly keys
		$results = [];
		foreach ($counts as $type => $count) {
			$results[ucfirst($type)] = $count;
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
		// Return zeros if database doesn't exist - don't create empty database
		if (!$this->dbExists()) {
			return [
				'Pending'     => 0,
				'In-Progress' => 0,
				'Failed'      => 0,
				'Total'       => 0,
			];
		}

		// Use a single query with GROUP BY for efficiency
		$sql = <<<SQL
			SELECT status, COUNT(*) as count
			FROM jobqueue
			GROUP BY status
		SQL;

		$stmt = $this->getDb()->query($sql);

		// Initialize all status counts to 0
		$counts = [
			JobData::STATUS_PENDING     => 0,
			JobData::STATUS_IN_PROGRESS => 0,
			JobData::STATUS_FAILED      => 0,
		];

		$total = 0;
		if ($stmt) {
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$status = $row['status'] ?? '';
				$count  = intval($row['count'] ?? 0);
				if (isset($counts[$status])) {
					$counts[$status] = $count;
				}
				$total += $count;
			}
		}

		// Return in specific order with display-friendly keys
		return [
			'Pending'     => $counts[JobData::STATUS_PENDING],
			'In-Progress' => $counts[JobData::STATUS_IN_PROGRESS],
			'Failed'      => $counts[JobData::STATUS_FAILED],
			'Total'       => $total,
		];
	}

	/** @return array<string,int>  */
	public function queueByTypeForCollection(string $collection): array
	{
		// Return zeros if database doesn't exist - don't create empty database
		if (!$this->dbExists()) {
			$results = [];
			foreach (JobData::TYPE_LIST as $type) {
				$results[ucfirst($type)] = 0;
			}
			ksort($results);

			return $results;
		}

		// Use a single query with GROUP BY for efficiency
		$sql = <<<SQL
			SELECT type, COUNT(*) as count
			FROM jobqueue
			WHERE collection = :collection
			GROUP BY type
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();

		// Initialize all type counts to 0
		$counts = [];
		foreach (JobData::TYPE_LIST as $type) {
			$counts[$type] = 0;
		}

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$type  = $row['type'] ?? '';
			$count = intval($row['count'] ?? 0);
			if (isset($counts[$type])) {
				$counts[$type] = $count;
			}
		}

		// Convert to display-friendly keys
		$results = [];
		foreach ($counts as $type => $count) {
			$results[ucfirst($type)] = $count;
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
		// Return zeros if database doesn't exist - don't create empty database
		if (!$this->dbExists()) {
			return [
				'Pending'     => 0,
				'In-Progress' => 0,
				'Failed'      => 0,
				'Total'       => 0,
			];
		}

		// Use a single query with GROUP BY for efficiency
		$sql = <<<SQL
			SELECT status, COUNT(*) as count
			FROM jobqueue
			WHERE collection = :collection
			GROUP BY status
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();

		// Initialize all status counts to 0
		$counts = [
			JobData::STATUS_PENDING     => 0,
			JobData::STATUS_IN_PROGRESS => 0,
			JobData::STATUS_FAILED      => 0,
		];

		$total = 0;
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$status = $row['status'] ?? '';
			$count  = intval($row['count'] ?? 0);
			if (isset($counts[$status])) {
				$counts[$status] = $count;
			}
			$total += $count;
		}

		// Return in specific order with display-friendly keys
		return [
			'Pending'     => $counts[JobData::STATUS_PENDING],
			'In-Progress' => $counts[JobData::STATUS_IN_PROGRESS],
			'Failed'      => $counts[JobData::STATUS_FAILED],
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

	/**
	 * Fetch all in-progress jobs (typically stuck jobs from crashed processes).
	 *
	 * @return array<JobData>
	 */
	public function fetchInProgressJobs(): array
	{
		$sql = <<<SQL
			SELECT * FROM jobqueue
			WHERE status = :status
			ORDER BY id ASC
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':status', JobData::STATUS_IN_PROGRESS);
		$stmt->execute();

		$jobs = [];
		while ($record = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$jobs[] = JobData::fromArray($record);
		}

		return $jobs;
	}

	/**
	 * Reset all in-progress jobs to pending (for recovery from crashed processes).
	 *
	 * @return int Number of jobs reset
	 */
	public function resetInProgressJobs(): int
	{
		$inProgressJobs = $this->fetchInProgressJobs();
		$count          = 0;

		foreach ($inProgressJobs as $job) {
			$this->resetJobStatus($job);
			$count++;
		}

		return $count;
	}

	/**
	 * Remove failed jobs older than specified days.
	 *
	 * @return int Number of jobs pruned
	 */
	public function pruneFailedJobs(int $daysOld = 30): int
	{
		if (!$this->dbExists()) {
			return 0;
		}

		$sql = <<<SQL
			DELETE FROM jobqueue
			WHERE status = :status
			AND updatedAt < datetime('now', :interval)
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':status', JobData::STATUS_FAILED);
		$stmt->bindValue(':interval', "-{$daysOld} days");
		$stmt->execute();

		return $stmt->rowCount();
	}

	/**
	 * Run VACUUM to reclaim disk space from deleted rows.
	 * Should be called periodically after bulk deletions.
	 */
	public function vacuum(): void
	{
		if (!$this->dbExists()) {
			return;
		}

		$this->getDb()->exec('VACUUM');
	}

	/**
	 * Perform database maintenance: prune old failed jobs and vacuum.
	 *
	 * @return array{pruned: int, vacuumed: bool}
	 */
	public function maintenance(int $daysOld = 30): array
	{
		$pruned = $this->pruneFailedJobs($daysOld);
		$this->vacuum();

		return [
			'pruned'   => $pruned,
			'vacuumed' => true,
		];
	}
}

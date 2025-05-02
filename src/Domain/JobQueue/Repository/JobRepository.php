<?php

namespace TotalCMS\Domain\JobQueue\Repository;

use PDO;
use RuntimeException;
use DomainException;
use TotalCMS\Domain\JobQueue\Data\JobData;

final class JobRepository
{
	private PDO $db;
	private const DB_PATH = __DIR__ . '/../../../../resources/jobqueue';

	public function __construct()
	{
		$this->db = $this->createDb();
	}

	private function dbExists(): bool
	{
		return file_exists(self::DB_PATH);
	}

	private function createDb(): PDO
	{
		$exists = $this->dbExists();
		$db     = new PDO('sqlite:' . self::DB_PATH);

		if (!$exists) {
			$db->exec(<<<SQL
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

		return $db;
	}

	private function markInProgress(JobData $job): JobData
	{
		$job->attempts++;

		return $this->updateJobStatus($job, JobData::STATUS_IN_PROGRESS);
	}

	private function updateJobStatus(JobData $job, string $status): JobData
	{
		if (!in_array($status, JobData::STATUS_LIST)) {
			throw new DomainException(sprintf('Invalid job status %s', $status));
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
		$stmt = $this->db->prepare($sql);
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
		$stmt = $this->db->prepare($sql);
		$stmt->execute();

		if (!$stmt) {
			throw new RuntimeException('Failed to prepare query to fetch next job');
		}
		$record = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$record) {
			throw new DomainException('No pending jobs found');
		}

		$job = JobData::fromArray($record);

		$job = $this->markInProgress($job);

		return $job;
	}

	public function hasPendingJobs(): bool
	{
		$stmt = $this->db->prepare("SELECT * FROM jobqueue WHERE status = 'pending' LIMIT 1");
		$stmt->execute();
		$record = $stmt->fetch(PDO::FETCH_ASSOC);

		return !empty($record);
	}

	public function delete(JobData $job): bool
	{
		$stmt = $this->db->prepare('DELETE FROM jobqueue WHERE id = :id');
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
		$stmt = $this->db->prepare('SELECT * FROM jobqueue WHERE id = :id');
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		$record = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$record) {
			throw new DomainException(sprintf('Job with ID %d not found', $id));
		}

		return JobData::fromArray($record);
	}

	public function queueJob(string $type, string $collection, string $payload = ''): JobData
	{
		if (!in_array($type, JobData::TYPE_LIST)) {
			throw new DomainException(sprintf('Invalid job type %s', $type));
		}
		$sql = <<<SQL
			INSERT INTO jobqueue (type, payload, collection)
			VALUES (:type, :payload, :collection)
		SQL;
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':type', $type);
		$stmt->bindValue(':payload', $payload);
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();

		$id = $this->db->lastInsertId();
		if (!$id) {
			throw new DomainException('Failed to insert job into the queue');
		}

		return $this->fetchJobById(intval($id));
	}

	/** @return array<string,int>  */
	public function queueStats(): array
	{
		$stmt  = $this->db->query('SELECT COUNT(*) as total FROM jobqueue');
		$total = $stmt ? $stmt->fetchColumn() : 0;

		$stmt    = $this->db->query("SELECT COUNT(*) as pending FROM jobqueue WHERE status = 'pending'");
		$pending = $stmt ? $stmt->fetchColumn() : 0;

		$stmt   = $this->db->query("SELECT COUNT(*) as failed FROM jobqueue WHERE status = 'failed'");
		$failed = $stmt ? $stmt->fetchColumn() : 0;

		$stmt       = $this->db->query("SELECT COUNT(*) as inProgress FROM jobqueue WHERE status = 'in_progress'");
		$inProgress = $stmt ? $stmt->fetchColumn() : 0;

		return [
			'Pending'     => intval($pending),
			'In-Progress' => intval($inProgress),
			'Failed'      => intval($failed),
			'Total'       => intval($total),
		];
	}

	/** @return array<string,int>  */
	public function queueStatsForCollection(string $collection): array
	{
		$stmt = $this->db->prepare('SELECT COUNT(*) as total FROM jobqueue WHERE collection = :collection');
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$total = $stmt ? $stmt->fetchColumn() : 0;

		$stmt = $this->db->prepare("SELECT COUNT(*) as pending FROM jobqueue WHERE status = 'pending' AND collection = :collection");
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$pending = $stmt ? $stmt->fetchColumn() : 0;

		$stmt = $this->db->prepare("SELECT COUNT(*) as failed FROM jobqueue WHERE status = 'failed' AND collection = :collection");
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$failed = $stmt ? $stmt->fetchColumn() : 0;

		$stmt = $this->db->prepare("SELECT COUNT(*) as inProgress FROM jobqueue WHERE status = 'in_progress' AND collection = :collection");
		$stmt->bindValue(':collection', $collection);
		$stmt->execute();
		$inProgress = $stmt ? $stmt->fetchColumn() : 0;

		return [
			'Pending'     => intval($pending),
			'In-Progress' => intval($inProgress),
			'Failed'      => intval($failed),
			'Total'       => intval($total),
		];
	}

	public function clearQueue(): bool
	{
		$stmt = $this->db->prepare('DELETE FROM jobqueue');
		return $stmt->execute();
	}

	public function clearQueueForCollection(string $collection): bool
	{
		$stmt = $this->db->prepare('DELETE FROM jobqueue WHERE collection = :collection');
		$stmt->bindValue(':collection', $collection);
		return $stmt->execute();
	}
}

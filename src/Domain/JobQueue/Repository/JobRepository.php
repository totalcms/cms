<?php

namespace TotalCMS\Domain\JobQueue\Repository;

use PDO;
use PDOException;
use RuntimeException;
use DomainException;
use PHPUnit\Event\Runtime\PHP;
use TotalCMS\Domain\JobQueue\Data\JobData;

final class JobRepository
{
	private PDO $db;
	private const DB_PATH = __DIR__ . '/../../../../resources/jobqueue';

	private const CREATE_TABLE_SQL = <<<SQL
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
	SQL;

	private const UPDATE_JOB_SQL = <<<SQL
		UPDATE jobqueue
		SET status = :status,
			updatedAt = CURRENT_TIMESTAMP,
			attempts = :attempts,
			lastError = :lastError
		WHERE id = :id
	SQL;

	private const INSERT_JOB_SQL = <<<SQL
		INSERT INTO jobqueue (type, payload, collection)
		VALUES (:type, :payload, :collection)
	SQL;

	private const SELECT_JOB_SQL = <<<SQL
		SELECT * FROM jobqueue
		WHERE id = :id
	SQL;

	private const FETCH_NEXT_JOB_SQL = <<<SQL
		SELECT * FROM jobqueue
		WHERE status = 'pending'
		ORDER BY id ASC
		LIMIT 1
	SQL;

	public function __construct()
	{
		$this->db = $this->createDb();
	}

	public function isLocked(): bool
	{
		try {
			$this->db->exec('BEGIN EXCLUSIVE TRANSACTION');
			$this->db->exec('ROLLBACK');
			return false;
		} catch (PDOException $e) {
			return true;
		}
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
			$db->exec(self::CREATE_TABLE_SQL);
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

		$stmt = $this->db->prepare(self::UPDATE_JOB_SQL);
		$stmt->bindValue(':id', $job->id);
		$stmt->bindValue(':status', $job->status);
		$stmt->bindValue(':attempts', $job->attempts);
		$stmt->bindValue(':lastError', $job->lastError);
		$stmt->execute();

		return $job;
	}

	public function fetchNextJob(): JobData
	{
		$stmt = $this->db->prepare(self::FETCH_NEXT_JOB_SQL);
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
		$stmt = $this->db->prepare(self::FETCH_NEXT_JOB_SQL);
		$stmt->execute();
		$record = $stmt->fetch(PDO::FETCH_ASSOC);

		return !empty($record);
	}

	public function markDone(JobData $job): JobData
	{
		return $this->updateJobStatus($job, JobData::STATUS_DONE);
	}

	public function markFailed(JobData $job, string $error): JobData
	{
		$job->lastError = $error;

		return $this->updateJobStatus($job, JobData::STATUS_FAILED);
	}

	public function fetchJobById(int $id): JobData
	{
		$stmt = $this->db->prepare(self::SELECT_JOB_SQL);
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
		$stmt = $this->db->prepare(self::INSERT_JOB_SQL);
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
}

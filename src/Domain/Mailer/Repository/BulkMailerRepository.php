<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Repository;

use TotalCMS\Domain\Mailer\Data\BulkMailLogData;
use TotalCMS\Support\Config;

/**
 * Repository for bulk mailer send tracking using SQLite.
 */
class BulkMailerRepository
{
	private ?\PDO $db = null;

	public function __construct(private readonly Config $config)
	{
	}

	private function getDbPath(): string
	{
		return $this->config->datadir . '/.system/bulkmailer';
	}

	private function dbExists(): bool
	{
		return file_exists($this->getDbPath());
	}

	/**
	 * Lazy-load database connection.
	 */
	private function getDb(): \PDO
	{
		if ($this->db instanceof \PDO) {
			return $this->db;
		}

		$dbPath = $this->getDbPath();
		$exists = $this->dbExists();

		$dir = dirname($dbPath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$this->db = new \PDO('sqlite:' . $dbPath);

		if (!$exists) {
			$this->db->exec(<<<SQL
				CREATE TABLE bulk_send_log (
					id          INTEGER PRIMARY KEY AUTOINCREMENT,
					batchId     TEXT NOT NULL,
					mailerId    TEXT NOT NULL,
					collection  TEXT NOT NULL,
					objectId    TEXT NOT NULL,
					sentTo      TEXT NOT NULL DEFAULT '',
					status      TEXT NOT NULL DEFAULT 'sent',
					error       TEXT DEFAULT NULL,
					scheduledAt DATETIME DEFAULT NULL,
					sentAt      DATETIME DEFAULT CURRENT_TIMESTAMP
				);
				CREATE INDEX idx_batch_id ON bulk_send_log (batchId);
				CREATE INDEX idx_mailer_object ON bulk_send_log (mailerId, objectId);
			SQL);
		}

		return $this->db;
	}

	/**
	 * Log a bulk send result.
	 *
	 * @param array<string,string|null> $data
	 */
	public function log(array $data): void
	{
		$sql = <<<SQL
			INSERT INTO bulk_send_log (batchId, mailerId, collection, objectId, sentTo, status, error, scheduledAt)
			VALUES (:batchId, :mailerId, :collection, :objectId, :sentTo, :status, :error, :scheduledAt)
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':batchId', $data['batchId'] ?? '');
		$stmt->bindValue(':mailerId', $data['mailerId'] ?? '');
		$stmt->bindValue(':collection', $data['collection'] ?? '');
		$stmt->bindValue(':objectId', $data['objectId'] ?? '');
		$stmt->bindValue(':sentTo', $data['sentTo'] ?? '');
		$stmt->bindValue(':status', $data['status'] ?? 'sent');
		$stmt->bindValue(':error', $data['error'] ?? null);
		$stmt->bindValue(':scheduledAt', $data['scheduledAt'] ?? null);
		$stmt->execute();
	}

	/**
	 * Check if a mailer has already been sent to a specific object.
	 */
	public function hasBeenSent(string $mailerId, string $objectId): bool
	{
		if (!$this->dbExists()) {
			return false;
		}

		$sql = <<<SQL
			SELECT 1 FROM bulk_send_log
			WHERE mailerId = :mailerId AND objectId = :objectId AND status = 'sent'
			LIMIT 1
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->bindValue(':objectId', $objectId);
		$stmt->execute();

		return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
	}

	/**
	 * Fetch statistics for a batch.
	 *
	 * @return array{total:int,sent:int,failed:int,skipped:int}
	 */
	public function fetchBatchStats(string $batchId): array
	{
		if (!$this->dbExists()) {
			return ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
		}

		$sql = <<<SQL
			SELECT status, COUNT(*) as count
			FROM bulk_send_log
			WHERE batchId = :batchId
			GROUP BY status
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':batchId', $batchId);
		$stmt->execute();

		$stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$status = (string)($row['status'] ?? '');
			$count  = intval($row['count'] ?? 0);
			if (isset($stats[$status])) {
				$stats[$status] = $count;
			}
			$stats['total'] += $count;
		}

		return $stats;
	}

	/**
	 * Fetch all log entries for a batch.
	 *
	 * @return array<BulkMailLogData>
	 */
	public function fetchBatchLog(string $batchId): array
	{
		if (!$this->dbExists()) {
			return [];
		}

		$sql = <<<SQL
			SELECT * FROM bulk_send_log
			WHERE batchId = :batchId
			ORDER BY id ASC
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':batchId', $batchId);
		$stmt->execute();

		$logs = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$logs[] = BulkMailLogData::fromArray($row);
		}

		return $logs;
	}

	/**
	 * Count emails successfully sent since a given datetime.
	 */
	public function countSentSince(string $since): int
	{
		if (!$this->dbExists()) {
			return 0;
		}

		$sql = <<<SQL
			SELECT COUNT(*) as count
			FROM bulk_send_log
			WHERE status = 'sent' AND sentAt >= :since
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':since', $since);
		$stmt->execute();

		$row = $stmt->fetch(\PDO::FETCH_ASSOC);

		return intval($row['count'] ?? 0);
	}

	/**
	 * Fetch statistics for a mailer.
	 *
	 * @return array{total:int,sent:int,failed:int,skipped:int}
	 */
	public function fetchMailerStats(string $mailerId): array
	{
		if (!$this->dbExists()) {
			return ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
		}

		$sql = <<<SQL
			SELECT status, COUNT(*) as count
			FROM bulk_send_log
			WHERE mailerId = :mailerId
			GROUP BY status
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->execute();

		$stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$status = (string)($row['status'] ?? '');
			$count  = intval($row['count'] ?? 0);
			if (isset($stats[$status])) {
				$stats[$status] = $count;
			}
			$stats['total'] += $count;
		}

		return $stats;
	}
}

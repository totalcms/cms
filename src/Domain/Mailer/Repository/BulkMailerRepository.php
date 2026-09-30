<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Repository;

use TotalCMS\Domain\Mailer\Data\BulkBatchSummaryData;
use TotalCMS\Domain\Mailer\Data\BulkMailLogData;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
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
		return PathUtils::absolutePath($this->config->systemDir(), 'bulkmailer');
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

		// Created unconditionally so databases from before 3.6.2 gain it too.
		// One row per queued batch: the send log only learns about an object
		// once its job runs, so this is what pending counts are measured against.
		$this->db->exec(<<<SQL
			CREATE TABLE IF NOT EXISTS bulk_batches (
				batchId     TEXT PRIMARY KEY,
				mailerId    TEXT NOT NULL,
				collection  TEXT NOT NULL,
				queued      INTEGER NOT NULL DEFAULT 0,
				excluded    INTEGER NOT NULL DEFAULT 0,
				overrideTo  TEXT NOT NULL DEFAULT '',
				scheduledAt DATETIME DEFAULT NULL,
				createdAt   DATETIME DEFAULT CURRENT_TIMESTAMP
			);
			CREATE INDEX IF NOT EXISTS idx_batches_mailer ON bulk_batches (mailerId);
		SQL);

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
	 * Check if a mailer has already been delivered to a specific object.
	 *
	 * Only real deliveries count. A test send to an "Override To" address is
	 * logged with that address in `sentTo`; real sends leave it empty. Counting
	 * test sends here made proofing a batch to yourself silently skip every
	 * real recipient on the follow-up send.
	 */
	public function hasBeenSent(string $mailerId, string $objectId): bool
	{
		if (!$this->dbExists()) {
			return false;
		}

		$sql = <<<SQL
			SELECT 1 FROM bulk_send_log
			WHERE mailerId = :mailerId AND objectId = :objectId AND status = 'sent' AND sentTo = ''
			LIMIT 1
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->bindValue(':objectId', $objectId);
		$stmt->execute();

		return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
	}

	/**
	 * IDs of every object the mailer has been delivered to, as a lookup set.
	 * One query for the whole collection, rather than hasBeenSent() per object.
	 *
	 * @return array<string,true>
	 */
	public function fetchDeliveredObjectIds(string $mailerId): array
	{
		if (!$this->dbExists()) {
			return [];
		}

		$sql = <<<SQL
			SELECT DISTINCT objectId FROM bulk_send_log
			WHERE mailerId = :mailerId AND status = 'sent' AND sentTo = ''
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->execute();

		$ids = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$ids[(string)$row['objectId']] = true;
		}

		return $ids;
	}

	/**
	 * Record a queued batch so its progress can be reported later.
	 */
	public function recordBatch(string $batchId, string $mailerId, string $collection, int $queued, int $excluded, ?string $overrideTo, ?string $scheduledAt): void
	{
		$sql = <<<SQL
			INSERT INTO bulk_batches (batchId, mailerId, collection, queued, excluded, overrideTo, scheduledAt)
			VALUES (:batchId, :mailerId, :collection, :queued, :excluded, :overrideTo, :scheduledAt)
		SQL;

		$stmt = $this->getDb()->prepare($sql);
		$stmt->bindValue(':batchId', $batchId);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->bindValue(':collection', $collection);
		$stmt->bindValue(':queued', $queued, \PDO::PARAM_INT);
		$stmt->bindValue(':excluded', $excluded, \PDO::PARAM_INT);
		$stmt->bindValue(':overrideTo', $overrideTo ?? '');
		$stmt->bindValue(':scheduledAt', $scheduledAt);
		$stmt->execute();
	}

	/**
	 * The mailer's most recent batches with per-status counts, newest first.
	 *
	 * Counts are per object, by the latest log row for that object in the
	 * batch — a job that failed and then succeeded on retry counts once, as
	 * sent. Batches queued before bulk_batches existed are still listed from
	 * the log alone, without a queued total.
	 *
	 * @return list<BulkBatchSummaryData>
	 */
	public function fetchMailerBatches(string $mailerId, int $limit = 10): array
	{
		if (!$this->dbExists()) {
			return [];
		}

		$db = $this->getDb();

		$stmt = $db->prepare(<<<SQL
			SELECT l.batchId,
				MAX(l.collection) AS collection,
				MAX(l.sentTo)     AS sentTo,
				MIN(l.sentAt)     AS firstAt,
				MAX(l.sentAt)     AS lastAt,
				SUM(l.status = 'sent')    AS sent,
				SUM(l.status = 'failed')  AS failed,
				SUM(l.status = 'skipped') AS skipped
			FROM bulk_send_log l
			WHERE l.mailerId = :mailerId
				AND l.id IN (SELECT MAX(id) FROM bulk_send_log WHERE mailerId = :mailerId GROUP BY batchId, objectId)
			GROUP BY l.batchId
		SQL);
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->execute();

		$logged = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$logged[(string)$row['batchId']] = $row;
		}

		$stmt = $db->prepare('SELECT * FROM bulk_batches WHERE mailerId = :mailerId');
		$stmt->bindValue(':mailerId', $mailerId);
		$stmt->execute();

		$batches = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$batchId           = (string)$row['batchId'];
			$batches[$batchId] = BulkBatchSummaryData::fromRows($row, $logged[$batchId] ?? null);
			unset($logged[$batchId]);
		}
		foreach ($logged as $batchId => $row) {
			$batches[(string)$batchId] = BulkBatchSummaryData::fromRows(null, $row);
		}

		$batches = array_values($batches);
		usort($batches, static fn (BulkBatchSummaryData $a, BulkBatchSummaryData $b): int => strcmp($b->startedAt, $a->startedAt));

		return array_slice($batches, 0, $limit);
	}

	/**
	 * Fetch statistics for a batch.
	 *
	 * @return array{total:int,sent:int,failed:int,skipped:int}
	 */
	public function fetchBatchStats(string $batchId): array
	{
		return $this->statusCounts('batchId', $batchId);
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
		return $this->statusCounts('mailerId', $mailerId);
	}

	/**
	 * Sent / failed / skipped counts (and their total) for the rows where
	 * `$column` equals `$value`. `$column` is one of this class's own column
	 * names, never caller input.
	 *
	 * @return array{total:int,sent:int,failed:int,skipped:int}
	 */
	private function statusCounts(string $column, string $value): array
	{
		$stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
		if (!$this->dbExists()) {
			return $stats;
		}

		$stmt = $this->getDb()->prepare("SELECT status, COUNT(*) as count FROM bulk_send_log WHERE {$column} = :value GROUP BY status");
		$stmt->bindValue(':value', $value);
		$stmt->execute();

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

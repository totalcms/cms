<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Repository;

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * The activity log, in SQLite at tcms-data/.system/activity.
 *
 * SQLite rather than a log file because the page filters by user, collection
 * and date over months of rows, which a text log can only answer by reading
 * every line. The connection is lazy, so a request that records nothing never
 * opens the file.
 */
class ActivityRepository
{
	private const PRUNE_INTERVAL = 86400;

	private ?\PDO $db = null;

	public function __construct(private readonly Config $config)
	{
	}

	public function record(ActivityEntry $entry): void
	{
		$stmt = $this->getDb()->prepare(<<<SQL
			INSERT INTO activity (createdAt, event, level, actorKind, userId, authCollection, clientId, apiKeyId, initiator, collection, objectId, summary, detail)
			VALUES (:createdAt, :event, :level, :actorKind, :userId, :authCollection, :clientId, :apiKeyId, :initiator, :collection, :objectId, :summary, :detail)
		SQL);

		$stmt->execute([
			':createdAt'      => $entry->createdAt > 0 ? $entry->createdAt : time(),
			':event'          => $entry->event,
			':level'          => $entry->level,
			':actorKind'      => $entry->actorKind,
			':userId'         => $entry->userId,
			':authCollection' => $entry->authCollection,
			':clientId'       => $entry->clientId,
			':apiKeyId'       => $entry->apiKeyId,
			':initiator'      => $entry->initiator,
			':collection'     => $entry->collection,
			':objectId'       => $entry->objectId,
			':summary'        => $entry->summary,
			':detail'         => $entry->detail === [] ? '' : (string)json_encode($entry->detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
	}

	/**
	 * Newest first, one page of the filter.
	 *
	 * @return list<ActivityEntry>
	 */
	public function query(ActivityFilter $filter): array
	{
		if (!$this->dbExists()) {
			return [];
		}

		[$where, $params] = $this->where($filter);

		$stmt = $this->getDb()->prepare("SELECT * FROM activity {$where} ORDER BY createdAt DESC, id DESC LIMIT :limit OFFSET :offset");
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', ActivityFilter::PAGE_SIZE, \PDO::PARAM_INT);
		$stmt->bindValue(':offset', $filter->offset(), \PDO::PARAM_INT);
		$stmt->execute();

		$entries = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$entries[] = ActivityEntry::fromRow($row);
		}

		return $entries;
	}

	/**
	 * Every row the filter matches, newest first, one at a time — for the
	 * CSV export, which ignores the page.
	 *
	 * @return \Generator<int,ActivityEntry>
	 */
	public function each(ActivityFilter $filter): \Generator
	{
		if (!$this->dbExists()) {
			return;
		}

		[$where, $params] = $this->where($filter);

		$stmt = $this->getDb()->prepare("SELECT * FROM activity {$where} ORDER BY createdAt DESC, id DESC");
		$stmt->execute($params);

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			yield ActivityEntry::fromRow($row);
		}
	}

	public function count(ActivityFilter $filter): int
	{
		if (!$this->dbExists()) {
			return 0;
		}

		[$where, $params] = $this->where($filter);

		$stmt = $this->getDb()->prepare("SELECT COUNT(*) FROM activity {$where}");
		$stmt->execute($params);

		return (int)$stmt->fetchColumn();
	}

	/**
	 * Distinct values of one column, for the page's filter menus.
	 *
	 * @param 'event'|'actorKind'|'collection' $column
	 *
	 * @return list<string>
	 */
	public function distinct(string $column): array
	{
		if (!$this->dbExists()) {
			return [];
		}

		$stmt = $this->getDb()->query("SELECT DISTINCT {$column} FROM activity WHERE {$column} != '' ORDER BY {$column}");
		if ($stmt === false) {
			return [];
		}

		return array_values(array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
	}

	/**
	 * Delete rows older than $retentionDays, at most once a day. Returns the
	 * number deleted (0 when it was not due).
	 */
	public function pruneIfDue(int $retentionDays, int $now): int
	{
		$db   = $this->getDb();
		$stmt = $db->query("SELECT value FROM activity_meta WHERE key = 'lastPrune'");
		$last = $stmt === false ? 0 : (int)$stmt->fetchColumn();
		if ($now - $last < self::PRUNE_INTERVAL) {
			return 0;
		}

		$db->prepare("INSERT OR REPLACE INTO activity_meta (key, value) VALUES ('lastPrune', :now)")->execute([':now' => (string)$now]);

		return $this->prune($now - (max(1, $retentionDays) * 86400));
	}

	/** Delete every row recorded before $cutoff (a Unix timestamp). */
	public function prune(int $cutoff): int
	{
		$stmt = $this->getDb()->prepare('DELETE FROM activity WHERE createdAt < :cutoff');
		$stmt->execute([':cutoff' => $cutoff]);

		return $stmt->rowCount();
	}

	/**
	 * @return array{0: string, 1: array<string,string|int>}
	 */
	private function where(ActivityFilter $filter): array
	{
		$clauses = [];
		$params  = [];

		foreach (['event' => $filter->event, 'actorKind' => $filter->actorKind, 'collection' => $filter->collection, 'level' => $filter->level] as $column => $value) {
			if ($value !== '') {
				$clauses[]            = "{$column} = :{$column}";
				$params[":{$column}"] = $value;
			}
		}

		if ($filter->user !== '') {
			$clauses[]       = '(userId = :user OR apiKeyId = :user OR clientId = :user)';
			$params[':user'] = $filter->user;
		}
		if ($filter->from !== '') {
			$clauses[]       = 'createdAt >= :from';
			$params[':from'] = (int)strtotime($filter->from . ' 00:00:00');
		}
		if ($filter->to !== '') {
			$clauses[]     = 'createdAt <= :to';
			$params[':to'] = (int)strtotime($filter->to . ' 23:59:59');
		}

		return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
	}

	private function getDbPath(): string
	{
		return PathUtils::absolutePath($this->config->systemDir(), 'activity');
	}

	private function dbExists(): bool
	{
		return $this->db instanceof \PDO || file_exists($this->getDbPath());
	}

	private function getDb(): \PDO
	{
		if ($this->db instanceof \PDO) {
			return $this->db;
		}

		$dbPath = $this->getDbPath();
		FileUtils::ensureDirectory(dirname($dbPath));

		// Several requests can record at once; wait for the write lock rather
		// than fail the insert.
		$db = new \PDO('sqlite:' . $dbPath, null, null, [\PDO::ATTR_TIMEOUT => 5]);
		$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		$db->exec(<<<SQL
			CREATE TABLE IF NOT EXISTS activity (
				id             INTEGER PRIMARY KEY AUTOINCREMENT,
				createdAt      INTEGER NOT NULL,
				event          TEXT NOT NULL,
				level          TEXT NOT NULL DEFAULT 'info',
				actorKind      TEXT NOT NULL DEFAULT '',
				userId         TEXT NOT NULL DEFAULT '',
				authCollection TEXT NOT NULL DEFAULT '',
				clientId       TEXT NOT NULL DEFAULT '',
				apiKeyId       TEXT NOT NULL DEFAULT '',
				initiator      TEXT NOT NULL DEFAULT '',
				collection     TEXT NOT NULL DEFAULT '',
				objectId       TEXT NOT NULL DEFAULT '',
				summary        TEXT NOT NULL DEFAULT '',
				detail         TEXT NOT NULL DEFAULT ''
			);
			CREATE INDEX IF NOT EXISTS idx_activity_created ON activity (createdAt);
			CREATE INDEX IF NOT EXISTS idx_activity_event ON activity (event, createdAt);
			CREATE INDEX IF NOT EXISTS idx_activity_user ON activity (userId, createdAt);
			CREATE INDEX IF NOT EXISTS idx_activity_collection ON activity (collection, createdAt);
			CREATE TABLE IF NOT EXISTS activity_meta (
				key   TEXT PRIMARY KEY,
				value TEXT NOT NULL
			);
		SQL);

		return $this->db = $db;
	}
}

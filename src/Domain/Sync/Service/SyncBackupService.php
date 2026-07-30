<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Snapshots schemas and objects before a sync overwrite replaces them.
 *
 * Sync's upsert import is authoritative and blind: whatever arrives replaces
 * whatever is there, with no confirmation on the receiving side. This service
 * gives that overwrite an undo. It runs on the machine BEING overwritten —
 * on a push that's the remote applying the payload, on a pull it's the local
 * instance — because a backup written by the sending side would preserve the
 * file that survives and lose the one that's destroyed.
 *
 * Layout (inside the data directory, so backups live with the content they
 * protect and survive application updates):
 *
 *   .system/backups/schemas/{id}/{id}-{YYYYMMDD-HHMMSS}.json
 *   .system/backups/objects/{collection}/{id}/{id}-{YYYYMMDD-HHMMSS}.json
 *
 * Restore is a manual file copy by design — no UI yet. Each item keeps its
 * last KEEP snapshots; identical consecutive syncs don't stack duplicates
 * (the newest backup is compared by content before writing a new one).
 *
 * Failures here must never fail the sync itself: every entry point swallows
 * storage errors after logging them. A backup is insurance, and refusing to
 * apply a deliberate sync because the insurance write failed would invert
 * its purpose.
 */
class SyncBackupService
{
	private const BACKUP_ROOT = '.system/backups';

	private const CUSTOM_SCHEMA_DIR = '.schemas';

	/** Snapshots kept per schema/object; oldest pruned on write. */
	private const KEEP = 10;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JumpStartImporter);
	}

	/**
	 * Snapshot a custom schema file before it is overwritten.
	 * No-op when the schema doesn't exist yet (a create, nothing to lose).
	 */
	public function backupSchema(string $id): void
	{
		if (!$this->isSafeSegment($id)) {
			return;
		}

		$this->backup(
			sprintf('%s/%s.json', self::CUSTOM_SCHEMA_DIR, $id),
			sprintf('%s/schemas/%s', self::BACKUP_ROOT, $id),
			$id,
		);
	}

	/**
	 * Snapshot a collection's settings (.meta.json) before a sync overwrite
	 * replaces them. No-op when the collection doesn't exist yet.
	 */
	public function backupCollectionMeta(string $id): void
	{
		if (!$this->isSafeSegment($id)) {
			return;
		}

		$this->backup(
			PathUtils::buildPath(collection: $id, filename: '.meta.json'),
			sprintf('%s/collections/%s', self::BACKUP_ROOT, $id),
			$id,
		);
	}

	/**
	 * Snapshot an object file before it is overwritten.
	 * No-op when the object doesn't exist yet.
	 */
	public function backupObject(string $collection, string $id): void
	{
		if (!$this->isSafeSegment($collection) || !$this->isSafeSegment($id)) {
			return;
		}

		$this->backup(
			PathUtils::buildPath(collection: $collection, filename: $id . '.json'),
			sprintf('%s/objects/%s/%s', self::BACKUP_ROOT, $collection, $id),
			$id,
		);
	}

	private function backup(string $sourcePath, string $backupDir, string $id): void
	{
		try {
			if (!$this->filesystem->fileExists($sourcePath)) {
				return;
			}

			$contents = $this->filesystem->read($sourcePath);
			$existing = $this->listBackupsNewestFirst($backupDir);

			// Re-syncing unchanged content is common (a full push mirrors
			// everything); don't stack identical snapshots.
			if ($existing !== [] && $this->filesystem->read($existing[0]) === $contents) {
				return;
			}

			$this->filesystem->write(
				sprintf('%s/%s-%s.json', $backupDir, $id, date('Ymd-His')),
				$contents,
			);

			foreach (array_slice($existing, self::KEEP - 1) as $stale) {
				$this->filesystem->delete($stale);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Sync backup failed — continuing with import', [
				'source' => $sourcePath,
				'error'  => $e->getMessage(),
			]);
		}
	}

	/**
	 * Backup filenames embed a fixed-width UTC-agnostic timestamp
	 * ({id}-YYYYMMDD-HHMMSS.json), so a reverse lexicographic sort IS
	 * newest-first — no filesystem mtime needed.
	 *
	 * @return list<string>
	 */
	private function listBackupsNewestFirst(string $backupDir): array
	{
		if (!$this->filesystem->directoryExists($backupDir)) {
			return [];
		}

		$files = $this->filesystem->listFiles($backupDir);
		rsort($files);

		return $files;
	}

	/**
	 * Ids arriving here come from an imported payload, not from disk. Refuse
	 * anything that could step outside the backup tree; the import itself
	 * will reject these ids anyway, so silently skipping the backup is safe.
	 */
	private function isSafeSegment(string $segment): bool
	{
		return $segment !== '' && $segment !== '.' && $segment !== '..'
			&& !str_contains($segment, '/') && !str_contains($segment, '\\');
	}
}

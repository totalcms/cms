<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Per-item snapshot history for schemas, collection settings and objects.
 *
 * Two writers feed it:
 *
 *   - Sync, before an upsert overwrites a file on the receiving side
 *     (`backupSchema()` / `backupCollectionMeta()` / `backupObject()` read the
 *     file that is about to be lost).
 *   - The object lifecycle, after every save and delete
 *     (`snapshotObject()` is handed the pre-save state the event carries,
 *     because by the time `object.updated` fires the file on disk is already
 *     the new version).
 *
 * Both land in one tree so an operator has a single place to look:
 *
 *   .system/backups/schemas/{id}/{id}-{YYYYMMDD-HHMMSS}.json
 *   .system/backups/collections/{id}/{id}-{YYYYMMDD-HHMMSS}.json
 *   .system/backups/objects/{collection}/{id}/{id}-{YYYYMMDD-HHMMSS}[-n].{json|md}
 *
 * It lives inside the data directory so backups stay with the content they
 * protect and survive application updates. Only the record is kept — never
 * uploaded files or images, which would multiply disk use with every edit.
 *
 * Retention is count AND age: each item keeps its newest `keep` snapshots,
 * and anything older than `maxAgeDays` goes regardless. Count alone let one
 * busy afternoon evict the version from last week; age alone let a
 * never-edited record hold a snapshot forever. Identical consecutive writes
 * don't stack (the newest snapshot is compared by content first).
 *
 * Failures here must never fail the save, delete or sync that triggered
 * them: every entry point swallows storage errors after logging. A backup
 * is insurance, and refusing a deliberate write because the insurance write
 * failed would invert its purpose.
 */
class BackupStore
{
	public const BACKUP_ROOT = '.system/backups';

	private const CUSTOM_SCHEMA_DIR = '.schemas';

	public const DEFAULT_KEEP = 10;

	public const DEFAULT_MAX_AGE_DAYS = 30;

	private readonly LoggerInterface $logger;

	private readonly int $keep;

	private readonly int $maxAgeDays;

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		private readonly ObjectRepository $objects,
		LoggerFactory $loggerFactory,
		?Config $config = null,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Backups);

		$settings         = $config->backups ?? [];
		$this->keep       = max(1, (int)($settings['keep'] ?? self::DEFAULT_KEEP));
		$this->maxAgeDays = max(0, (int)($settings['maxAgeDays'] ?? self::DEFAULT_MAX_AGE_DAYS));
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

		$this->backupFile(
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

		$this->backupFile(
			PathUtils::buildPath(collection: $id, filename: '.meta.json'),
			sprintf('%s/collections/%s', self::BACKUP_ROOT, $id),
			$id,
		);
	}

	/**
	 * Snapshot an object's on-disk file before it is overwritten.
	 * No-op when the object doesn't exist yet.
	 */
	public function backupObject(string $collection, string $id): void
	{
		if (!$this->isSafeSegment($collection) || !$this->isSafeSegment($id)) {
			return;
		}

		$sourcePath = $this->objects->objectPath($collection, $id);
		if ($sourcePath === null) {
			return;
		}

		$this->backupFile($sourcePath, $this->objectDir($collection, $id), $id);
	}

	/**
	 * Snapshot an object from its decoded data rather than its file. This is
	 * the lifecycle path: the event payload carries the pre-save state, and
	 * the file on disk is already the new version (or, on a delete, gone).
	 *
	 * Always written as JSON regardless of the collection's on-disk format,
	 * so a collection that later converts formats does not strand its own
	 * history. Restore re-encodes through the object writer.
	 *
	 * @param array<string,mixed> $data
	 */
	public function snapshotObject(string $collection, string $id, array $data): void
	{
		if (!$this->isSafeSegment($collection) || !$this->isSafeSegment($id)) {
			return;
		}

		$contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($contents === false) {
			$this->logger->warning('Object snapshot skipped — data could not be encoded', [
				'collection' => $collection,
				'id'         => $id,
				'error'      => json_last_error_msg(),
			]);

			return;
		}

		$this->write($this->objectDir($collection, $id), $id, 'json', $contents);
	}

	/**
	 * Every snapshot of one object, newest first.
	 *
	 * @return list<array{file:string,path:string,at:string,format:string,bytes:int}>
	 */
	public function listObjectSnapshots(string $collection, string $id): array
	{
		if (!$this->isSafeSegment($collection) || !$this->isSafeSegment($id)) {
			return [];
		}

		$entries = [];
		foreach ($this->listNewestFirst($this->objectDir($collection, $id)) as $path) {
			$parsed = $this->parseName($path);
			if ($parsed === null) {
				continue;
			}
			[, , $extension, $at] = $parsed;

			$entries[] = [
				'file'   => basename($path),
				'path'   => $path,
				'at'     => $at->format(\DateTimeInterface::ATOM),
				'format' => $extension === 'md' ? 'markdown' : 'json',
				'bytes'  => $this->sizeOf($path),
			];
		}

		return $entries;
	}

	/**
	 * Raw contents of one snapshot. `$file` is a bare filename from
	 * {@see listObjectSnapshots()} — anything with a path separator is
	 * refused so a caller can't read outside the object's own folder.
	 */
	public function readObjectSnapshot(string $collection, string $id, string $file): ?string
	{
		if (!$this->isSafeSegment($collection) || !$this->isSafeSegment($id) || !$this->isSafeSegment($file)) {
			return null;
		}

		$path = $this->objectDir($collection, $id) . '/' . $file;

		return $this->filesystem->fileExists($path) ? $this->filesystem->read($path) : null;
	}

	/**
	 * The newest snapshot's filename, or null when there is none.
	 */
	public function latestObjectSnapshot(string $collection, string $id): ?string
	{
		$all = $this->listObjectSnapshots($collection, $id);

		return $all === [] ? null : $all[0]['file'];
	}

	private function objectDir(string $collection, string $id): string
	{
		return sprintf('%s/objects/%s/%s', self::BACKUP_ROOT, $collection, $id);
	}

	private function backupFile(string $sourcePath, string $backupDir, string $id): void
	{
		try {
			if (!$this->filesystem->fileExists($sourcePath)) {
				return;
			}

			$this->write(
				$backupDir,
				$id,
				pathinfo($sourcePath, PATHINFO_EXTENSION),
				$this->filesystem->read($sourcePath),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Backup failed — continuing', [
				'source' => $sourcePath,
				'error'  => $e->getMessage(),
			]);
		}
	}

	private function write(string $backupDir, string $id, string $extension, string $contents): void
	{
		try {
			$existing = $this->listNewestFirst($backupDir);

			// Re-syncing unchanged content is common (a full push mirrors
			// everything), and a save that touched nothing is too — don't
			// stack identical snapshots.
			if ($existing !== [] && $this->filesystem->read($existing[0]) === $contents) {
				return;
			}

			$this->filesystem->write($this->freshName($backupDir, $id, $extension, $existing), $contents);

			$this->prune($existing);
		} catch (\Throwable $e) {
			$this->logger->warning('Backup failed — continuing', [
				'dir'   => $backupDir,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * `{id}-{Ymd-His}.{ext}`, with a `-n` suffix when this second already has
	 * a snapshot — two quick saves must not overwrite each other. Ordering is
	 * by parsed (timestamp, n), never by raw string: `-` sorts before `.`, so
	 * a lexical sort would rank the bare name above its `-1` sibling.
	 *
	 * @param list<string> $existing
	 */
	private function freshName(string $backupDir, string $id, string $extension, array $existing): string
	{
		$stem = sprintf('%s/%s-%s', $backupDir, $id, date('Ymd-His'));
		$name = "{$stem}.{$extension}";

		$taken = array_flip($existing);
		for ($n = 1; isset($taken[$name]); $n++) {
			$name = "{$stem}-{$n}.{$extension}";
		}

		return $name;
	}

	/**
	 * Apply both retention rules to the snapshots that existed BEFORE the
	 * write just made (so the fresh one is never a candidate): drop past
	 * the count limit, and drop anything older than the age limit.
	 *
	 * @param list<string> $existingNewestFirst
	 */
	private function prune(array $existingNewestFirst): void
	{
		$cutoff = $this->maxAgeDays > 0 ? time() - ($this->maxAgeDays * 86400) : null;

		foreach ($existingNewestFirst as $i => $path) {
			// Position 0 is the previous newest; the fresh write sits above it,
			// so the count limit leaves keep-1 of these alive.
			$tooMany = $i >= $this->keep - 1;
			$tooOld  = $cutoff !== null && $this->timestampOf($path) < $cutoff;

			if ($tooMany || $tooOld) {
				$this->filesystem->delete($path);
			}
		}
	}

	/**
	 * Newest first, ordered by the timestamp and collision sequence embedded
	 * in each filename — never by filesystem mtime (a snapshot copied between
	 * machines keeps its place) and never by raw string (`-` sorts before
	 * `.`, so `x-120000-1.json` would lexically rank BELOW `x-120000.json`
	 * despite being the later write). Files that don't parse as snapshots
	 * sink to the end and are left alone.
	 *
	 * @return list<string>
	 */
	private function listNewestFirst(string $backupDir): array
	{
		if (!$this->filesystem->directoryExists($backupDir)) {
			return [];
		}

		$files = $this->filesystem->listFiles($backupDir);
		usort($files, function (string $a, string $b): int {
			[$tsA, $seqA] = $this->parseName($a) ?? [-1, -1];
			[$tsB, $seqB] = $this->parseName($b) ?? [-1, -1];

			return [$tsB, $seqB, $b] <=> [$tsA, $seqA, $a];
		});

		return $files;
	}

	private function timestampOf(string $path): int
	{
		$parsed = $this->parseName($path);

		return $parsed === null ? PHP_INT_MAX : $parsed[0]; // unparseable — never age-pruned
	}

	/**
	 * `{id}-YYYYMMDD-HHMMSS[-n].{json|md}` → [unix timestamp, n, extension,
	 * parsed time], or null when the name isn't a snapshot. Filenames are
	 * written with date() in the server's zone and parsed the same way, so
	 * the time reported back is the time the operator saw — not a UTC
	 * reinterpretation of it.
	 *
	 * @return array{int,int,string,\DateTimeImmutable}|null
	 */
	private function parseName(string $path): ?array
	{
		if (preg_match('/-(\d{8})-(\d{6})(?:-(\d+))?\.(json|md)$/', basename($path), $m) !== 1) {
			return null;
		}

		$at = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1] . '-' . $m[2]);
		if (!$at instanceof \DateTimeImmutable) {
			return null;
		}

		return [$at->getTimestamp(), (int)$m[3], $m[4], $at];
	}

	private function sizeOf(string $path): int
	{
		try {
			return $this->filesystem->fileSize($path);
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * Ids may arrive from an imported payload or a CLI argument, not from
	 * disk. Refuse anything that could step outside the backup tree.
	 */
	private function isSafeSegment(string $segment): bool
	{
		return !in_array($segment, ['', '.', '..'], true)
			&& !str_contains($segment, '/') && !str_contains($segment, '\\');
	}
}

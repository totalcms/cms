<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Keeps uploaded files that are about to be deleted, so restoring a record can
 * give it its files back.
 *
 * BackupStore keeps the record; this keeps what the record points at. It is
 * off until asked for, under `backups` in the settings:
 *
 * - `keepImages`, `keepFiles` (default 0): how many earlier files each image
 *   or file slot keeps. A slot is one field of one object: a top-level `image`
 *   or `file` property, or one inside a card or a deck item.
 * - `trash` (default off): the files removed from a `gallery` or a `depot` are
 *   kept, with no count, until they are too old.
 * - `maxFileSizeMB` (default 100, 0 for none): a larger file is never kept.
 * - `maxAgeDays`: anything kept longer than this goes. The trash always
 *   empties: with the age rule off it is kept for 30 days.
 * - `enable`: off switches all of this off with the record history it serves.
 *
 * Callers do not say what a file is. They say "this path is about to be
 * deleted" ({@see setAside()}), and each file under it is kept or left by
 * what {@see FileKindResolver} says it is. A file that is left is deleted by
 * the caller, as it always was.
 *
 * Kept files sit beside the object's snapshots, in one dated folder per
 * set-aside that mirrors the property's own folders:
 *
 *   .system/backups/objects/{collection}/{id}/files/{property}/{stamp}/[{folders}/]{name}
 *   .system/backups/objects/{collection}/{id}/trash/{property}/{stamp}/[{folders}/]{name}
 *
 * Files are MOVED in and out, never copied. A file that is about to be deleted
 * costs nothing to keep that way, however large it is, and a file put back
 * stops being history.
 *
 * Like BackupStore, nothing here may fail the save or delete it rides on:
 * every entry point logs a storage error and carries on.
 */
class FileBackupStore
{
	public const DEFAULT_MAX_FILE_MB = 100;

	/** How long the trash is kept when the age rule (`maxAgeDays`) is off. */
	public const TRASH_DAYS_WITHOUT_AGE_RULE = 30;

	/** Setting that says how many earlier files a slot of each kind keeps. */
	private const KEEP_SETTING = [FileKindResolver::IMAGE => 'keepImages', FileKindResolver::FILE => 'keepFiles'];

	/** Holds the time of the last age sweep; a dotfile, so no listing shows it. */
	private const SWEEP_MARKER = '.files-swept';

	private const SWEEP_INTERVAL = 86400;

	private readonly LoggerInterface $logger;

	private readonly FileHistoryTree $tree;

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		private readonly FileKindResolver $kinds,
		LoggerFactory $loggerFactory,
		// Required, never optional: the container skips optional parameters.
		private readonly Config $config,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Backups);
		$this->tree   = new FileHistoryTree($filesystem);
	}

	/** Whether any setting asks for files to be kept. When false, setAside() looks at nothing. */
	public function active(): bool
	{
		return $this->limit(FileKindResolver::IMAGE) !== 0
			|| $this->limit(FileKindResolver::FILE) !== 0
			|| $this->limit(FileKindResolver::GALLERY) !== 0;
	}

	/**
	 * Keep what the settings say of the files at a path that is about to be
	 * deleted. Call it just before the delete, with the delete's own arguments:
	 * a property, a folder under it (`$subpath`), or one file or folder in
	 * either (`$name`).
	 *
	 * @return list<array{kept:string, live:string, version:string}> what was moved, for {@see undo()}
	 */
	public function setAside(string $collection, string $id, string $property, ?string $subpath = null, ?string $name = null): array
	{
		$moves = [];
		if (!$this->active() || !$this->safe($collection, $id, $property)) {
			return $moves;
		}

		try {
			$target = PathUtils::buildPath($collection, $id, $property, $name, $subpath);
			$this->keep($collection, $id, $property, $this->tree->filesAt($target), $moves);
			if ($moves !== []) {
				$this->sweep();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('File history failed — continuing', [
				'collection' => $collection,
				'id'         => $id,
				'property'   => $property,
				'error'      => $e->getMessage(),
			]);
		}

		return $moves;
	}

	/**
	 * Put back what a set-aside moved: the delete it was for did not happen.
	 *
	 * @param list<array{kept:string, live:string, version:string}> $moves
	 */
	public function undo(array $moves): void
	{
		foreach ($moves as $move) {
			try {
				if ($this->filesystem->fileExists($move['kept'])) {
					$this->filesystem->move($move['kept'], $move['live']);
				}
				$this->tree->dropIfEmpty($move['version']);
			} catch (\Throwable $e) {
				$this->logger->warning('A kept file could not be put back', ['file' => $move['live'], 'error' => $e->getMessage()]);
			}
		}
	}

	/**
	 * After a record has been restored, make one of its file references true.
	 * Call it once the record is saved, never before: a restore the save
	 * refuses must move nothing.
	 *
	 * For an image or file slot the live file becomes the one the record names:
	 *
	 * - `present`: the named file is already there.
	 * - `restored`: it was kept and is back. Whatever it displaced was kept in
	 *   turn, whatever the settings say, so the restore can itself be undone.
	 * - `missing`: the record names a file that is neither live nor kept.
	 *   Nothing is touched.
	 * - `none`: the record names no file here, or this is not a place that
	 *   holds files. A file still in an empty slot is set aside when that
	 *   kind keeps history, and left where it is otherwise.
	 *
	 * For a gallery or a depot the named file is brought back from the trash
	 * when it is not there. The other files in it are never touched.
	 *
	 * @param string   $dir  the folder under the property the file sits in, `''` for the property's own
	 * @param int|null $size the size the record names, to pick among kept files that share the name
	 *
	 * @return 'present'|'restored'|'missing'|'none'
	 */
	public function reconcile(string $collection, string $id, string $property, string $dir, string $name, ?int $size): string
	{
		if (!$this->safe($collection, $id, $property)) {
			return 'none';
		}

		try {
			$base    = PathUtils::buildPath($collection, $id, $property);
			$liveDir = PathUtils::buildPath($collection, $id, $property, null, $dir);
			$dir     = trim(substr($liveDir, strlen($base)), '/');
			$kind    = $this->kinds->kind($collection, $property, $dir);
			if ($kind === null) {
				return 'none';
			}

			$live = [];
			foreach ($this->filesystem->directoryExists($liveDir) ? $this->filesystem->listFiles($liveDir) : [] as $path) {
				$live[basename($path)] = $path;
			}

			$slot = ['collection' => $collection, 'id' => $id, 'property' => $property, 'dir' => $dir, 'liveDir' => $liveDir, 'live' => $live];

			return $this->isTrash($kind)
				? $this->fromTrash($slot, $name, $size)
				: $this->intoSlot($slot, $kind, $name, $size);
		} catch (\Throwable $e) {
			$this->logger->warning('A restored record\'s file could not be put back', ['collection' => $collection, 'id' => $id, 'property' => $property, 'file' => $name, 'error' => $e->getMessage()]);

			return 'missing';
		}
	}

	/**
	 * Whether a file a record names can still be had, without moving anything:
	 * what {@see reconcile()} would find, for a page that shows it first.
	 *
	 * - `live`: it is in the property now.
	 * - `kept`: it is in the history or the trash, so a restore brings it back.
	 * - `gone`: it is nowhere.
	 * - `none`: no file is named, or this is not a place that holds files.
	 *
	 * @param string   $dir  the folder under the property the file sits in, `''` for the property's own
	 * @param int|null $size the size the record names
	 *
	 * @return 'live'|'kept'|'gone'|'none'
	 */
	public function state(string $collection, string $id, string $property, string $dir, string $name, ?int $size): string
	{
		if ($name === '' || !$this->safe($collection, $id, $property)) {
			return 'none';
		}

		try {
			$base    = PathUtils::buildPath($collection, $id, $property);
			$liveDir = PathUtils::buildPath($collection, $id, $property, null, $dir);
			$dir     = trim(substr($liveDir, strlen($base)), '/');
			$kind    = $this->kinds->kind($collection, $property, $dir);
			if ($kind === null) {
				return 'none';
			}
			if (!$this->safeSegment($name)) {
				return 'gone';
			}

			$trash = $this->isTrash($kind);
			$path  = $liveDir . '/' . $name;
			$live  = $this->filesystem->fileExists($path);
			if ($live && ($trash || $size === null || $this->filesystem->fileSize($path) === $size)) {
				return 'live';
			}

			$kept = $this->tree->find($this->tree->propertyDir($collection, $id, $property, $trash), $dir, $name, $size, exact: $live);

			return $kept !== null ? 'kept' : ($live ? 'live' : 'gone');
		} catch (\Throwable $e) {
			$this->logger->warning('File history could not be read', ['collection' => $collection, 'id' => $id, 'property' => $property, 'error' => $e->getMessage()]);

			return 'gone';
		}
	}

	/**
	 * A gallery or a depot: bring the named file back from the trash when it is
	 * not there. Nothing else in it is touched.
	 *
	 * @param array{collection:string, id:string, property:string, dir:string, liveDir:string, live:array<string,string>} $slot
	 *
	 * @return 'present'|'restored'|'missing'|'none'
	 */
	private function fromTrash(array $slot, string $name, ?int $size): string
	{
		if ($name === '') {
			return 'none';
		}
		if (!$this->safeSegment($name)) {
			return 'missing';
		}
		if (isset($slot['live'][$name])) {
			return 'present';
		}

		$wanted = $this->tree->find($this->tree->propertyDir($slot['collection'], $slot['id'], $slot['property'], true), $slot['dir'], $name, $size, exact: false);
		if ($wanted === null) {
			return 'missing';
		}

		$this->filesystem->move($wanted['path'], $slot['liveDir'] . '/' . $name);
		$this->tree->dropIfEmpty($wanted['version']);

		return 'restored';
	}

	/**
	 * An image or file slot: make its one live file the one the record names.
	 *
	 * @param array{collection:string, id:string, property:string, dir:string, liveDir:string, live:array<string,string>} $slot
	 *
	 * @return 'present'|'restored'|'missing'|'none'
	 */
	private function intoSlot(array $slot, string $kind, string $name, ?int $size): string
	{
		$live = array_values($slot['live']);

		if ($name === '') {
			// The record has no file here. A file still on disk is set aside when
			// this kind keeps history, and left where it is otherwise.
			if ($live !== [] && $this->limit($kind) !== 0) {
				$moves = [];
				$this->keep($slot['collection'], $slot['id'], $slot['property'], $live, $moves);
			}

			return 'none';
		}
		if (!$this->safeSegment($name)) {
			return 'missing';
		}

		// A file is often replaced by one of the same name (a corrected
		// brochure.pdf). The name is then no proof: swap only when the live
		// file is not the size the record names and a kept one is.
		$sameName = isset($slot['live'][$name]);
		if ($sameName && ($size === null || $this->filesystem->fileSize($slot['live'][$name]) === $size)) {
			return 'present';
		}

		$wanted = $this->tree->find($this->tree->propertyDir($slot['collection'], $slot['id'], $slot['property'], false), $slot['dir'], $name, $size, exact: $sameName);
		if ($wanted === null) {
			return $sameName ? 'present' : 'missing';
		}

		if ($live !== []) {
			// The file being restored is one of the kept ones: it must not be
			// pruned on its way out. And a restore never destroys the file it
			// displaces, whatever the settings say.
			$moves = [];
			$this->keep($slot['collection'], $slot['id'], $slot['property'], $live, $moves, force: true, protect: $wanted['version']);
		}

		// Renders may be of another file that had this name.
		$this->filesystem->deleteDirectory($slot['liveDir'] . '/.cache');
		$this->filesystem->move($wanted['path'], $slot['liveDir'] . '/' . $name);
		$this->tree->dropIfEmpty($wanted['version']);

		return 'restored';
	}

	/**
	 * Everything kept for one object: earlier files first, then the trash;
	 * within a property, newest first.
	 *
	 * @return list<array{property:string, path:string, name:string, size:int, at:string, trash:bool}>
	 */
	public function versions(string $collection, string $id): array
	{
		if (!$this->safe($collection, $id, 'x')) {
			return [];
		}

		try {
			return $this->tree->listing($collection, $id);
		} catch (\Throwable $e) {
			$this->logger->warning('File history could not be read', ['collection' => $collection, 'id' => $id, 'error' => $e->getMessage()]);

			return [];
		}
	}

	/**
	 * At most once a day, clear every kept file and every trashed file past
	 * its age, whichever object it belongs to.
	 *
	 * A set-aside only prunes the property it touches. A deleted object never
	 * sets anything aside again, and neither does a field nobody edits, so
	 * without this their kept files would hold their disk forever. The job
	 * runner calls it (`tcms jobs:process` and the cron URL); a set-aside calls
	 * it too, so a site with no cron still clears old files.
	 *
	 * @return int how many dated folders were removed
	 */
	public function sweep(): int
	{
		try {
			$root = BackupStore::BACKUP_ROOT . '/objects';
			if (!$this->filesystem->directoryExists($root)) {
				return 0;
			}

			$marker = $root . '/' . self::SWEEP_MARKER;
			$last   = $this->filesystem->fileExists($marker) ? (int)$this->filesystem->read($marker) : 0;
			if (time() - $last < self::SWEEP_INTERVAL) {
				return 0;
			}

			// Marked first: a sweep that fails part-way is tried again tomorrow,
			// not on every upload until then.
			$this->filesystem->write($marker, (string)time());

			$removed = 0;
			foreach ($this->filesystem->listDirectories($root) as $collection) {
				foreach ($this->filesystem->listDirectories($collection) as $object) {
					foreach ([FileHistoryTree::FILES, FileHistoryTree::TRASH] as $area) {
						$cutoff = $this->cutoff($area === FileHistoryTree::TRASH);
						if ($cutoff === null || !$this->filesystem->directoryExists($object . '/' . $area)) {
							continue;
						}
						foreach ($this->filesystem->listDirectories($object . '/' . $area) as $property) {
							$removed += $this->tree->pruneAge($this->tree->versionDirs($property), $cutoff);
						}
					}
				}
			}

			return $removed;
		} catch (\Throwable $e) {
			$this->logger->warning('The file history age sweep failed', ['error' => $e->getMessage()]);

			return 0;
		}
	}

	/**
	 * Move the files the settings want kept into one new dated folder per area,
	 * then apply the count and age rules to what was there before.
	 *
	 * @param list<string>                                          $files   live paths, all under the property
	 * @param list<array{kept:string, live:string, version:string}> $moves   added to as files are moved
	 * @param bool                                                  $force   keep them whatever the settings say: a restore must never destroy the file it displaces
	 * @param string|null                                           $protect a dated folder the pruning must leave alone
	 */
	private function keep(string $collection, string $id, string $property, array $files, array &$moves, bool $force = false, ?string $protect = null): void
	{
		$base     = PathUtils::buildPath($collection, $id, $property);
		$maxBytes = $force ? null : $this->maxBytes();
		$version  = [];
		$before   = [];
		$slots    = [];

		foreach ($files as $file) {
			$dir  = trim(substr(dirname($file), strlen($base)), '/');
			$kind = $this->kinds->kind($collection, $property, $dir);
			if ($kind === null || (!$force && $this->limit($kind) === 0)) {
				continue;
			}
			if ($maxBytes !== null && $this->filesystem->fileSize($file) > $maxBytes) {
				continue;
			}

			$trash = $this->isTrash($kind);
			$root  = $this->tree->propertyDir($collection, $id, $property, $trash);
			if (!isset($version[$root])) {
				$before[$root]  = [$this->tree->versionDirs($root), $trash];
				$version[$root] = $this->tree->freshVersion($root, $before[$root][0]);
			}

			$kept = $version[$root] . ($dir === '' ? '' : '/' . $dir) . '/' . basename($file);
			$this->filesystem->move($file, $kept);
			$moves[] = ['kept' => $kept, 'live' => $file, 'version' => $version[$root]];

			$slots[$kind . '|' . $dir] = [$kind, $dir, $root];
		}

		// The folder just written is never a candidate, so a forced set-aside
		// keeps one even when the setting says none.
		foreach ($slots as [$kind, $dir, $root]) {
			$limit = $this->limit($kind);
			if ($limit !== null) {
				$this->tree->pruneSlot(array_values(array_diff($before[$root][0], [$protect])), $dir, max(1, $limit));
			}
		}
		foreach ($before as [$versions, $trash]) {
			$cutoff = $this->cutoff($trash);
			if ($cutoff !== null) {
				$this->tree->pruneAge(array_values(array_diff($versions, [$protect])), $cutoff);
			}
		}
	}

	/**
	 * How many earlier files a slot of this kind keeps: 0 for none, null for no
	 * count (the trash, when it is on).
	 */
	private function limit(string $kind): ?int
	{
		$settings = $this->config->backups;

		// With object history off there is no record snapshot to restore, so a
		// kept file would have nothing to bring it back.
		if (!($settings['enable'] ?? true)) {
			return 0;
		}

		if ($this->isTrash($kind)) {
			return ($settings['trash'] ?? false) ? null : 0;
		}

		return max(0, (int)($settings[self::KEEP_SETTING[$kind] ?? ''] ?? 0));
	}

	private function isTrash(string $kind): bool
	{
		return $kind === FileKindResolver::GALLERY || $kind === FileKindResolver::DEPOT;
	}

	/** The largest file that is kept, in bytes; null for no limit. */
	private function maxBytes(): ?int
	{
		$megabytes = max(0, (int)($this->config->backups['maxFileSizeMB'] ?? self::DEFAULT_MAX_FILE_MB));

		return $megabytes > 0 ? $megabytes * 1024 * 1024 : null;
	}

	/**
	 * The unix time before which a kept file is too old, or null when earlier
	 * files have no age rule. The trash always has one.
	 */
	private function cutoff(bool $trash): ?int
	{
		$days = max(0, (int)($this->config->backups['maxAgeDays'] ?? BackupStore::DEFAULT_MAX_AGE_DAYS));
		if ($days === 0 && $trash) {
			$days = self::TRASH_DAYS_WITHOUT_AGE_RULE;
		}

		return $days > 0 ? time() - ($days * 86400) : null;
	}

	private function safe(string $collection, string $id, string $property): bool
	{
		return $this->safeSegment($collection) && $this->safeSegment($id) && $this->safeSegment($property);
	}

	/** Refuse anything that could step outside the object's own folders. */
	private function safeSegment(string $segment): bool
	{
		return !in_array($segment, ['', '.', '..'], true)
			&& !str_contains($segment, '/') && !str_contains($segment, '\\');
	}
}

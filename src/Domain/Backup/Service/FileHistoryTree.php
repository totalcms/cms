<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * The folders the file history is kept in, and nothing about what belongs in
 * them: {@see FileBackupStore} decides that from the settings.
 *
 * Each object has two areas beside its snapshots, one for the earlier files of
 * its image and file slots and one for the trash of its galleries and depots.
 * In both, every set-aside is one dated folder that mirrors the property's own
 * folders:
 *
 *   .system/backups/objects/{collection}/{id}/files/{property}/{stamp}/[{folders}/]{name}
 *   .system/backups/objects/{collection}/{id}/trash/{property}/{stamp}/[{folders}/]{name}
 *
 * A dated folder is called a version here. `{stamp}` is `YYYYMMDD-HHMMSS`,
 * with `-n` when that second already has one; versions are always ordered by
 * that name, never by the filesystem's dates.
 */
final readonly class FileHistoryTree
{
	public const FILES = 'files';

	public const TRASH = 'trash';

	public function __construct(private StorageAdapterInterface $filesystem)
	{
	}

	private function objectDir(string $collection, string $id): string
	{
		return sprintf('%s/objects/%s/%s', BackupStore::BACKUP_ROOT, $collection, $id);
	}

	public function propertyDir(string $collection, string $id, string $property, bool $trash): string
	{
		return sprintf('%s/%s/%s', $this->objectDir($collection, $id), $trash ? self::TRASH : self::FILES, $property);
	}

	/**
	 * Everything kept for one object: earlier files first, then the trash;
	 * within a property, newest first.
	 *
	 * @return list<array{property:string, path:string, name:string, size:int, at:string, trash:bool}>
	 */
	public function listing(string $collection, string $id): array
	{
		$rows = [];
		foreach ([self::FILES, self::TRASH] as $area) {
			$dir = $this->objectDir($collection, $id) . '/' . $area;
			if (!$this->filesystem->directoryExists($dir)) {
				continue;
			}
			$properties = $this->filesystem->listDirectories($dir);
			sort($properties);

			foreach ($properties as $property) {
				foreach ($this->versionDirs($property) as $version) {
					$at = $this->parse($version);
					foreach ($this->filesAt($version) as $file) {
						$rows[] = [
							'property' => basename($property),
							'path'     => trim(substr(dirname($file), strlen($version)), '/'),
							'name'     => basename($file),
							'size'     => $this->filesystem->fileSize($file),
							'at'       => $at === null ? '' : $at[2]->format(\DateTimeInterface::ATOM),
							'trash'    => $area === self::TRASH,
						];
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * Every file at a path: the file itself, or all files under a folder. Dot
	 * folders are skipped: renders (`.cache`) are not history.
	 *
	 * @return list<string>
	 */
	public function filesAt(string $path): array
	{
		if ($this->filesystem->fileExists($path)) {
			return [$path];
		}
		if (!$this->filesystem->directoryExists($path)) {
			return [];
		}

		$files = array_values($this->filesystem->listFiles($path));
		foreach ($this->filesystem->listDirectories($path) as $dir) {
			if (!str_starts_with(basename($dir), '.')) {
				array_push($files, ...$this->filesAt($dir));
			}
		}

		return $files;
	}

	/**
	 * The kept file to give back for one slot: the one of that name whose size
	 * the record names, or else the newest of that name. With `$exact`, only
	 * the former will do.
	 *
	 * @return array{path:string, version:string}|null
	 */
	public function find(string $propertyDir, string $dir, string $name, ?int $size, bool $exact): ?array
	{
		$newest = null;
		foreach ($this->versionDirs($propertyDir) as $version) {
			$path = $version . ($dir === '' ? '' : '/' . $dir) . '/' . $name;
			if (!$this->filesystem->fileExists($path)) {
				continue;
			}

			$found = ['path' => $path, 'version' => $version];
			if ($size !== null && $this->filesystem->fileSize($path) === $size) {
				return $found;
			}
			$newest ??= $found;
		}

		return $exact ? null : $newest;
	}

	/**
	 * The dated folders of one property, newest first, ordered by the time and
	 * sequence in each name and never by the filesystem's dates.
	 *
	 * @return list<string>
	 */
	public function versionDirs(string $propertyDir): array
	{
		if (!$this->filesystem->directoryExists($propertyDir)) {
			return [];
		}

		$dirs = array_values(array_filter($this->filesystem->listDirectories($propertyDir), fn (string $dir): bool => $this->parse($dir) !== null));
		usort($dirs, function (string $a, string $b): int {
			[$timeA, $sequenceA] = $this->parse($a) ?? [0, 0];
			[$timeB, $sequenceB] = $this->parse($b) ?? [0, 0];

			return [$timeB, $sequenceB] <=> [$timeA, $sequenceA];
		});

		return $dirs;
	}

	/**
	 * `{Ymd-His}`, with `-n` when this second already has one: two set-asides
	 * in quick succession must not land in the same folder.
	 *
	 * @param list<string> $existing
	 */
	public function freshVersion(string $propertyDir, array $existing): string
	{
		$stem  = $propertyDir . '/' . date('Ymd-His');
		$taken = array_flip($existing);
		$name  = $stem;
		for ($n = 1; isset($taken[$name]); $n++) {
			$name = "{$stem}-{$n}";
		}

		return $name;
	}

	/**
	 * Apply the count rule to one slot: of the dated folders that hold a file
	 * for it, all but the newest `$keep - 1` lose it. (The folder just written
	 * is the newest and is not among them.).
	 *
	 * @param list<string> $versionsNewestFirst
	 */
	public function pruneSlot(array $versionsNewestFirst, string $dir, int $keep): void
	{
		$held = 0;
		foreach ($versionsNewestFirst as $version) {
			$slot = $version . ($dir === '' ? '' : '/' . $dir);
			if (!$this->filesystem->directoryExists($slot) || $this->filesystem->listFiles($slot) === []) {
				continue;
			}
			if (++$held > $keep - 1) {
				$this->filesystem->deleteDirectory($slot);
				$this->dropIfEmpty($version);
			}
		}
	}

	/**
	 * Remove the dated folders older than the cutoff.
	 *
	 * @param list<string> $versions
	 *
	 * @return int how many were removed
	 */
	public function pruneAge(array $versions, int $cutoff): int
	{
		$removed = 0;
		foreach ($versions as $version) {
			if (($this->parse($version)[0] ?? PHP_INT_MAX) < $cutoff && $this->filesystem->directoryExists($version)) {
				$this->filesystem->deleteDirectory($version);
				$removed++;
			}
		}

		return $removed;
	}

	/** A dated folder with no file left in it is not a version of anything. */
	public function dropIfEmpty(string $version): void
	{
		if ($this->filesystem->directoryExists($version) && $this->filesAt($version) === []) {
			$this->filesystem->deleteDirectory($version);
		}
	}

	/**
	 * `YYYYMMDD-HHMMSS[-n]` → [unix time, n, parsed time]; null for any other name.
	 *
	 * @return array{int,int,\DateTimeImmutable}|null
	 */
	private function parse(string $dir): ?array
	{
		if (preg_match('/^(\d{8}-\d{6})(?:-(\d+))?$/', basename($dir), $m) !== 1) {
			return null;
		}

		$at = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1]);

		return $at instanceof \DateTimeImmutable ? [$at->getTimestamp(), (int)($m[2] ?? 0), $at] : null;
	}
}

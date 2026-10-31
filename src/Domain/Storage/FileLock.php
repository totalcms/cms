<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * An exclusive flock on a sidecar "<file>.lock", held for the whole of a
 * read → change → write cycle so two processes changing the same file cannot
 * each overwrite the other's change. Atomic writes alone stop torn reads, not
 * lost updates.
 *
 * Inside a collection the guarded files are dot-files (.index.json,
 * .meta.json), so their lock files are too, and a collection listing never
 * takes one for an object. Lock files are left in place: deleting one while
 * another process waits on it would hand the two of them different locks.
 */
final readonly class FileLock
{
	public function __construct(private string $datadir)
	{
	}

	/**
	 * Run $fn while holding the lock for $path (relative to the datadir).
	 *
	 * @template T
	 *
	 * @param callable(): T $fn
	 *
	 * @return T
	 */
	public function withLock(string $path, callable $fn): mixed
	{
		$handle = $this->acquire($path);

		try {
			return $fn();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/** @return resource */
	private function acquire(string $path)
	{
		$lockPath = PathUtils::absolutePath($this->datadir, $path . '.lock');
		$dir      = dirname($lockPath);

		if (!FileUtils::ensureDirectory($dir)) {
			throw new \RuntimeException("Unable to create the directory for {$path}");
		}

		$handle = fopen($lockPath, 'c');
		if ($handle === false) {
			throw new \RuntimeException("Unable to open the lock file for {$path}");
		}
		if (!flock($handle, LOCK_EX)) {
			fclose($handle);

			throw new \RuntimeException("Unable to lock {$path}");
		}

		return $handle;
	}
}

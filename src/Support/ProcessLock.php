<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * The single-flight flock every cron entry point takes so overlapping ticks
 * cannot double-run: open the lock file, try a non-blocking exclusive lock,
 * hold it for the run, release it on the way out.
 */
final class ProcessLock
{
	/** @param resource $handle */
	private function __construct(private $handle, private readonly string $path)
	{
	}

	/** Null when the lock file cannot be opened at all. */
	public static function open(string $path): ?self
	{
		$handle = @fopen($path, 'c');

		return $handle === false ? null : new self($handle, $path);
	}

	/**
	 * Take the lock without waiting. False means another run holds it; the
	 * file handle is closed and this object is spent.
	 */
	public function acquire(): bool
	{
		if (flock($this->handle, LOCK_EX | LOCK_NB)) {
			return true;
		}

		fclose($this->handle);

		return false;
	}

	/** Write the holder's pid into the file, for operators looking at a stale lock. */
	public function stampPid(): void
	{
		ftruncate($this->handle, 0);
		fwrite($this->handle, (string)getmypid());
	}

	/** Release when the process ends, however it ends. `$unlink` also removes the file. */
	public function releaseOnShutdown(bool $unlink = true): void
	{
		register_shutdown_function(fn () => $this->release($unlink));
	}

	public function release(bool $unlink = false): void
	{
		if (!is_resource($this->handle)) {
			return;
		}

		flock($this->handle, LOCK_UN);
		fclose($this->handle);

		if ($unlink) {
			@unlink($this->path);
		}
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

use Psr\Log\LoggerInterface;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Read-modify-write for the small JSON documents under `.system`.
 *
 * Three guarantees, each of which was hand-rolled in five or six places
 * before this class and got one of them wrong:
 *
 *  - Writes go to a sibling temp file and move() into place. The local
 *    adapter's move() is a rename(), which is atomic on POSIX, so a reader
 *    sees the old document or the new one, never a torn one.
 *  - An optional exclusive flock on a sidecar `.lock` file spans the whole
 *    load → change → save cycle, for files with concurrent writers.
 *  - A malformed read is handled by a policy the caller must name. In
 *    particular RefuseWrites remembers the path and declines every save to it
 *    until a later load succeeds, so the empty value substituted for a broken
 *    file can never be written back over it.
 *
 * Everything but the lock and the 0600 mode goes through the storage adapter;
 * those two need a real path, which is why the datadir is injected.
 */
final class AtomicJsonStore
{
	/** @var array<string,true> paths whose last load was malformed under RefuseWrites */
	private array $corrupt = [];

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		private readonly string $datadir,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException under CorruptPolicy::Throw
	 */
	public function load(string $path, CorruptPolicy $policy): array
	{
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}

		$content = $this->filesystem->read($path);
		if (trim($content) === '') {
			return [];
		}

		$data = json_decode($content, true);
		if (is_array($data)) {
			unset($this->corrupt[$path]);

			/** @var array<string,mixed> $data */
			return $data;
		}

		$reason = json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'not a JSON object';

		return match ($policy) {
			CorruptPolicy::Throw => throw new \RuntimeException(
				"{$path} is not valid JSON and will not be overwritten ({$reason})",
			),
			CorruptPolicy::RefuseWrites => $this->refuse($path, $reason),
			CorruptPolicy::TreatAsEmpty => $this->fresh($path, $reason),
		};
	}

	/**
	 * Commit $data atomically. Returns false, and logs, when $path was last
	 * read as corrupt under RefuseWrites.
	 *
	 * @param array<string,mixed> $data
	 */
	public function save(string $path, array $data, bool $secret = false): bool
	{
		if (isset($this->corrupt[$path])) {
			$this->logger->error("Refusing to overwrite {$path}: its last read was malformed. Repair or delete the file to resume writes.");

			return false;
		}

		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException("Could not encode {$path} as JSON: " . json_last_error_msg());
		}

		$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
		$this->filesystem->write($tmp, $json);
		if ($secret) {
			// On the temp file, before the move, so the secret is never briefly
			// world-readable at its final path.
			@chmod(PathUtils::absolutePath($this->datadir, $tmp), 0600);
		}

		return $this->filesystem->move($tmp, $path);
	}

	/**
	 * load → $fn → save, optionally under an exclusive sidecar lock.
	 *
	 * @param callable(array<string,mixed>): array<string,mixed> $fn
	 */
	public function mutate(string $path, callable $fn, CorruptPolicy $policy, bool $lock = false, bool $secret = false): bool
	{
		$handle = $lock ? $this->acquireLock($path) : null;

		try {
			$data = $this->load($path, $policy);
			if (isset($this->corrupt[$path])) {
				return false;
			}

			return $this->save($path, $fn($data), $secret);
		} finally {
			if (is_resource($handle)) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
	}

	public function isCorrupt(string $path): bool
	{
		return isset($this->corrupt[$path]);
	}

	/** @return array<string,mixed> */
	private function refuse(string $path, string $reason): array
	{
		$this->corrupt[$path] = true;
		$this->logger->error("{$path} is not valid JSON ({$reason}); treating it as empty for this request and refusing to write it back.");

		return [];
	}

	/** @return array<string,mixed> */
	private function fresh(string $path, string $reason): array
	{
		$this->logger->warning("{$path} is not valid JSON ({$reason}); starting fresh.");

		return [];
	}

	/**
	 * Exclusive lock on a sidecar, so the descriptor survives the move() that
	 * replaces the data file. Same idiom as CronJobsAction's job-queue lock.
	 *
	 * @return resource
	 */
	private function acquireLock(string $path)
	{
		$lockPath = PathUtils::absolutePath($this->datadir, $path . '.lock');
		$dir      = dirname($lockPath);

		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
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

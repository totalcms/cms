<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Repository;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Repository for managing API keys stored in .system/apikeys.json.
 *
 * Every mutation is a read-modify-write of the WHOLE file, and one of them
 * (updateLastUsed) fires on every successful authentication. That combination
 * used to lose keys in production:
 *
 *   1. Request A rewrote the file in place, truncating it mid-write.
 *   2. Request B read the partial file, json_decode failed, and the old
 *      readFile() returned ['apikeys' => []] — indistinguishable from
 *      "this install has no keys".
 *   3. Request B wrote that empty list back. Every key gone, with no error
 *      anywhere, uncorrelated with deploys.
 *
 * Two concurrent authenticated requests were enough, which is why it only ever
 * showed up on live sites. Three rules close it, and all three matter:
 *
 *   - Mutations hold an exclusive flock on a sidecar .lock file, so a
 *     read-modify-write cycle cannot interleave with another one.
 *   - Writes go to a sibling .tmp file and rename() into place. rename() is
 *     atomic on POSIX, so a reader sees the old file or the new one, never a
 *     torn one.
 *   - An unreadable or malformed file THROWS. It is never treated as empty.
 *     Silently reinterpreting "I could not read your secrets" as "you have no
 *     secrets" is what turned a transient read into permanent data loss.
 *
 * Reads are deliberately unlocked. rename() atomicity is the guarantee; a
 * reader may see a slightly stale file but never an invalid one.
 *
 * All file content goes through the storage adapter, including the temp-file
 * write and the move that commits it — Flysystem's local move() is a rename(),
 * so the adapter already provides the atomicity. The only two things reaching
 * past it are the advisory lock and the 0600 mode, because the adapter
 * interface exposes neither, and both need a real path.
 *
 * @see \TotalCMS\Domain\Builder\Repository\ReloadPulseRepository for the same
 *      adapter-based write/move commit.
 * @see \TotalCMS\Action\Cron\CronJobsAction for the same datadir-resolved flock.
 */
class ApiKeyRepository extends StorageRepository
{
	private const FILE_PATH = '.system/apikeys.json';

	private const LOCK_FILE = '.system/apikeys.json.lock';

	/**
	 * Skip the lastUsed write when the stored stamp is already this recent.
	 *
	 * lastUsed is the only reason a read path writes at all, and it was
	 * responsible for effectively all of the write volume on this file — one
	 * full rewrite per authenticated request, every request. It is displayed
	 * as a coarse "when was this key last active" hint in the admin, so
	 * minute-level granularity costs nothing and removes the contention.
	 */
	private const LAST_USED_DEBOUNCE_SECONDS = 60;

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly string $datadir,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Get all API keys.
	 *
	 * @return array<ApiKeyData>
	 */
	public function getAll(): array
	{
		$data = $this->read();

		return array_map(
			fn (array $keyData): ApiKeyData => new ApiKeyData($keyData),
			$this->keysOf($data)
		);
	}

	/**
	 * Find an API key by its ID.
	 */
	public function findById(string $id): ?ApiKeyData
	{
		foreach ($this->getAll() as $key) {
			if ($key->id === $id) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Find an API key by its key string.
	 */
	public function findByKey(string $key): ?ApiKeyData
	{
		foreach ($this->getAll() as $apiKey) {
			if (hash_equals($apiKey->key, $key)) {
				return $apiKey;
			}
		}

		return null;
	}

	/**
	 * Save a new API key.
	 */
	public function save(ApiKeyData $apiKey): void
	{
		$this->mutate(function (array $data) use ($apiKey): array {
			$keys   = $this->keysOf($data);
			$keys[] = $apiKey->toArray();

			$data['apikeys'] = $keys;

			return $data;
		});
	}

	/**
	 * Update an existing API key (typically for lastUsed).
	 */
	public function update(ApiKeyData $apiKey): void
	{
		$this->mutate(function (array $data) use ($apiKey): array {
			$keys = $this->keysOf($data);

			foreach ($keys as $index => $keyData) {
				if (($keyData['id'] ?? null) === $apiKey->id) {
					$keys[$index] = $apiKey->toArray();
					break;
				}
			}

			$data['apikeys'] = $keys;

			return $data;
		});
	}

	/**
	 * Delete an API key by ID.
	 */
	public function delete(string $id): bool
	{
		$deleted = false;

		$this->mutate(function (array $data) use ($id, &$deleted): array {
			$keys = $this->keysOf($data);

			$remaining = array_values(
				array_filter($keys, static fn (array $keyData): bool => ($keyData['id'] ?? null) !== $id)
			);

			$deleted = count($remaining) !== count($keys);

			$data['apikeys'] = $remaining;

			return $data;
		});

		return $deleted;
	}

	/**
	 * Update the lastUsed timestamp for a key.
	 *
	 * Debounced — see LAST_USED_DEBOUNCE_SECONDS. The re-read inside mutate()
	 * is what makes this safe under concurrency: the copy found here is only
	 * used to decide *whether* to write, never as the payload.
	 */
	public function updateLastUsed(string $keyString): void
	{
		$apiKey = $this->findByKey($keyString);

		if (!$apiKey instanceof ApiKeyData) {
			return;
		}

		if (!$this->lastUsedIsStale($apiKey->lastUsed)) {
			return;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');

		$this->mutate(function (array $data) use ($apiKey, $now): array {
			$keys = $this->keysOf($data);

			foreach ($keys as $index => $keyData) {
				if (($keyData['id'] ?? null) === $apiKey->id) {
					$keyData['lastUsed'] = $now;
					$keys[$index]        = $keyData;
					break;
				}
			}

			$data['apikeys'] = $keys;

			return $data;
		});
	}

	/**
	 * Whether the stored lastUsed stamp is old enough to be worth rewriting.
	 */
	private function lastUsedIsStale(?string $lastUsed): bool
	{
		if ($lastUsed === null || $lastUsed === '') {
			return true;
		}

		$timestamp = strtotime($lastUsed);

		// An unparseable stamp is worth replacing with a good one.
		if ($timestamp === false) {
			return true;
		}

		return (time() - $timestamp) >= self::LAST_USED_DEBOUNCE_SECONDS;
	}

	/**
	 * Pull the key list out of the decoded file, tolerating a missing key.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return list<array<string,mixed>>
	 */
	private function keysOf(array $data): array
	{
		$keys = $data['apikeys'] ?? [];

		if (!is_array($keys)) {
			return [];
		}

		/** @var list<array<string,mixed>> $list */
		$list = array_values(array_filter($keys, is_array(...)));

		return $list;
	}

	/**
	 * Read-modify-write under an exclusive lock, committed atomically.
	 *
	 * The lock lives in a sidecar .lock file rather than the data file itself
	 * so the descriptor stays valid across the rename that replaces the data
	 * file underneath it.
	 *
	 * @param callable(array<string,mixed>): array<string,mixed> $callback
	 */
	private function mutate(callable $callback): void
	{
		$lock = $this->acquireLock();

		try {
			$updated = $callback($this->read());

			$json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

			if ($json === false) {
				throw new \RuntimeException('Failed to encode API keys to JSON: ' . json_last_error_msg());
			}

			$this->commit($json);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * Open and exclusively lock the sidecar lock file.
	 *
	 * A sidecar rather than the data file itself, so the descriptor stays valid
	 * across the move() that replaces the data file underneath it. Opened with
	 * fopen because the storage adapter has no locking API — this is the same
	 * datadir-resolved flock CronJobsAction uses for the job queue.
	 *
	 * @return resource
	 */
	private function acquireLock()
	{
		$lockPath = PathUtils::absolutePath($this->datadir, self::LOCK_FILE);

		// The adapter creates .system/ on its first write, but the lock is
		// taken before any write happens, so on a fresh install it may not
		// exist yet.
		$dir = dirname($lockPath);

		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException("Unable to create the API key directory: {$dir}");
		}

		$lock = fopen($lockPath, 'c');

		if ($lock === false) {
			throw new \RuntimeException("Unable to open the API key lock file: {$lockPath}");
		}

		if (!flock($lock, LOCK_EX)) {
			fclose($lock);

			throw new \RuntimeException("Unable to lock the API key file: {$lockPath}");
		}

		return $lock;
	}

	/**
	 * Write $json to a temp file and move it over the target.
	 *
	 * write-then-move through the adapter, matching ReloadPulseRepository and
	 * MigrationStateRepository: the local adapter's move() is a rename(), which
	 * is atomic on POSIX, so an unlocked reader gets the old file or the new
	 * one and never a half-written one. The random suffix keeps two temp files
	 * from colliding if the lock is ever bypassed.
	 *
	 * The mode is set on the temp file rather than after the move so the
	 * credentials are never briefly readable at the final path. chmod is the
	 * one thing here the adapter cannot express — same reason
	 * ExtensionSettingsManager takes the datadir.
	 */
	private function commit(string $json): void
	{
		$tmp = self::FILE_PATH . '.tmp.' . bin2hex(random_bytes(4));

		$this->filesystem->write($tmp, $json);

		@chmod(PathUtils::absolutePath($this->datadir, $tmp), 0600);

		$this->filesystem->move($tmp, self::FILE_PATH);
	}

	/**
	 * Read and decode the file.
	 *
	 * A missing file is a legitimately empty install. Anything else — an
	 * unreadable file, malformed JSON, a non-array payload — throws, because
	 * every caller of this either hands the result to an authorization check
	 * or writes it straight back. Returning [] here is what used to delete
	 * every key on the install.
	 *
	 * @return array<string,mixed>
	 */
	private function read(): array
	{
		if (!$this->filesystem->fileExists(self::FILE_PATH)) {
			return ['apikeys' => []];
		}

		// The adapter throws UnableToReadFile rather than returning false, so
		// an unreadable file already fails loudly instead of looking empty.
		$content = $this->filesystem->read(self::FILE_PATH);

		// A zero-byte file is the one ambiguous case. Treat it as empty rather
		// than fatal so an install that has never had a key written still works.
		if (trim($content) === '') {
			return ['apikeys' => []];
		}

		$data = json_decode($content, true);

		if (!is_array($data)) {
			throw new \RuntimeException(
				'The API key file is not valid JSON and will not be overwritten: '
				. self::FILE_PATH . ' (' . json_last_error_msg() . ')'
			);
		}

		/** @var array<string,mixed> $data */
		return $data;
	}
}

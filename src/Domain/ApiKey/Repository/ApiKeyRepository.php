<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Repository;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;

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
 * All three now live in AtomicJsonStore (lock, temp+move commit, 0600 on the
 * temp file, and the refuse-on-corrupt read policy); this class only says
 * which policy it wants — CorruptPolicy::Throw — and shapes the payload.
 *
 * @see \TotalCMS\Domain\Storage\AtomicJsonStore
 */
class ApiKeyRepository extends StorageRepository
{
	private const FILE_PATH = '.system/apikeys.json';

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
		private readonly AtomicJsonStore $store,
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
	 * Read-modify-write under the store's exclusive sidecar lock, committed
	 * atomically with 0600 on the temp file. A malformed file throws and is
	 * never overwritten (CorruptPolicy::Throw).
	 *
	 * @param callable(array<string,mixed>): array<string,mixed> $callback
	 */
	private function mutate(callable $callback): void
	{
		$ok = $this->store->mutate(
			self::FILE_PATH,
			fn (array $data): array => $callback($this->withDefaults($data)),
			CorruptPolicy::Throw,
			lock: true,
			secret: true,
		);

		if (!$ok) {
			throw new \RuntimeException('Unable to write the API key file: ' . self::FILE_PATH);
		}
	}

	/**
	 * Read and decode the file.
	 *
	 * A missing or blank file is a legitimately empty install. Anything else —
	 * an unreadable file, malformed JSON, a non-array payload — throws, because
	 * every caller of this either hands the result to an authorization check
	 * or writes it straight back. Returning [] here is what used to delete
	 * every key on the install.
	 *
	 * @return array<string,mixed>
	 */
	private function read(): array
	{
		return $this->withDefaults($this->store->load(self::FILE_PATH, CorruptPolicy::Throw));
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function withDefaults(array $data): array
	{
		return $data === [] ? ['apikeys' => []] : $data;
	}
}

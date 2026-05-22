<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

/**
 * Maintains a JSON reverse-index mapping resource URIs to subscribed session IDs.
 *
 * The index file lives at {tmpdir}/mcp-subscriptions.json — intentionally
 * OUTSIDE /mcp-sessions/ because McpSessionInvalidator::invalidateAll() wipes
 * every file in that directory whenever the tool surface changes. The
 * subscription index must outlive session invalidations so re-subscribed
 * clients (which re-initialize with fresh session ids after invalidation)
 * can pick up where they left off via purgeSession() of the stale ids.
 *
 * JSON shape:
 *   {
 *       "tcms://blog/": ["uuid-session-1", "uuid-session-3"],
 *       "tcms://products/": ["uuid-session-2"]
 *   }
 *
 * All mutating operations (add / remove / purgeSession / clear) acquire an
 * exclusive lock on a sidecar .lock file, then rewrite the data file via
 * temp-file + rename(). rename() is POSIX-atomic on Linux/macOS so an
 * unlocked reader observes either the old or the new complete file — never
 * a torn write.
 *
 * subscribersOf() is a snapshot-read and does NOT hold a lock. The
 * rename atomicity is the safety guarantee; a concurrent reader might see
 * a stale snapshot but never an invalid one.
 *
 * No fsync() — losing the index on a host crash is acceptable because
 * sessions re-subscribe on reconnect. A crash window costs at most one
 * `notifications/resources/updated` event per affected URI.
 */
class SubscriptionIndex
{
	public function __construct(private readonly string $indexFile) {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Add $sessionId to the subscriber set for $uri.
	 * Idempotent: a duplicate session ID is silently ignored.
	 */
	public function add(string $uri, string $sessionId): void
	{
		$this->mutate(function (array $data) use ($uri, $sessionId): array {
			$set = $data[$uri] ?? [];

			if (!in_array($sessionId, $set, true)) {
				$set[] = $sessionId;
			}

			$data[$uri] = $set;

			return $data;
		});
	}

	/**
	 * Remove $sessionId from the subscriber set for $uri.
	 * When the set becomes empty the URI key is dropped entirely.
	 * No-op if the session is not in the set.
	 */
	public function remove(string $uri, string $sessionId): void
	{
		$this->mutate(function (array $data) use ($uri, $sessionId): array {
			if (!isset($data[$uri])) {
				return $data;
			}

			/** @var list<string> $set */
			$set = array_values(
				array_filter(
					(array) $data[$uri],
					static fn (mixed $id): bool => $id !== $sessionId
				)
			);

			if ($set === []) {
				unset($data[$uri]);
			} else {
				$data[$uri] = $set;
			}

			return $data;
		});
	}

	/**
	 * Return the list of session IDs subscribed to $uri.
	 * Returns an empty array when the URI has no subscribers or the file doesn't exist.
	 *
	 * @return list<string>
	 */
	public function subscribersOf(string $uri): array
	{
		$data = $this->readSnapshot();

		return $data[$uri] ?? [];
	}

	/**
	 * Remove $sessionId from every URI it appears in.
	 * Called when a session expires or disconnects.
	 */
	public function purgeSession(string $sessionId): void
	{
		$this->mutate(function (array $data) use ($sessionId): array {
			$result = [];

			foreach ($data as $uri => $subscribers) {
				$filtered = array_values(
					array_filter(
						$subscribers,
						static fn (mixed $id): bool => $id !== $sessionId
					)
				);

				if ($filtered !== []) {
					$result[$uri] = $filtered;
				}
			}

			return $result;
		});
	}

	/**
	 * Wipe all entries.  Mainly useful in tests.
	 */
	public function clear(): void
	{
		$this->mutate(static fn (array $_data): array => []);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read-modify-write under an exclusive flock.
	 *
	 * Opens (and creates) the index file, acquires LOCK_EX, reads the current
	 * JSON, passes it through $callback, then writes the result back via an
	 * atomic rename of a sibling .tmp file.
	 *
	 * @param callable(array<string, list<string>>): array<string, list<string>> $callback
	 */
	private function mutate(callable $callback): void
	{
		$this->ensureDirectory();

		// Open (or create) the lock file.  We keep it separate from the data
		// file so the lock FD stays open during the rename.
		$lockFile = $this->indexFile . '.lock';
		$lock     = fopen($lockFile, 'c');

		if ($lock === false) {
			throw new \RuntimeException("SubscriptionIndex: cannot open lock file {$lockFile}");
		}

		try {
			flock($lock, LOCK_EX);

			$current = $this->readFile($this->indexFile);

			/** @var array<string, list<string>> $updated */
			$updated = $callback($current);

			$json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			if ($json === false) {
				throw new \RuntimeException('SubscriptionIndex: JSON encode failed: ' . json_last_error_msg());
			}

			$tmpFile = $this->indexFile . '.tmp';
			$written = file_put_contents($tmpFile, $json);

			if ($written === false) {
				throw new \RuntimeException("SubscriptionIndex: cannot write temporary file {$tmpFile}");
			}

			if (!rename($tmpFile, $this->indexFile)) {
				throw new \RuntimeException("SubscriptionIndex: rename({$tmpFile}, {$this->indexFile}) failed");
			}
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * Non-locking snapshot read.
	 *
	 * @return array<string, list<string>>
	 */
	private function readSnapshot(): array
	{
		return $this->readFile($this->indexFile);
	}

	/**
	 * Read and JSON-decode a file.  Returns [] on any error or missing file.
	 *
	 * @return array<string, list<string>>
	 */
	private function readFile(string $path): array
	{
		if (!file_exists($path)) {
			return [];
		}

		$content = file_get_contents($path);

		if ($content === false || $content === '') {
			return [];
		}

		$decoded = json_decode($content, true);

		if (!is_array($decoded)) {
			return [];
		}

		/** @var array<string, list<string>> $decoded */
		return $decoded;
	}

	/**
	 * Create the parent directory if it does not yet exist.
	 */
	private function ensureDirectory(): void
	{
		$dir = dirname($this->indexFile);

		if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new \RuntimeException("SubscriptionIndex: cannot create directory {$dir}");
		}
	}
}

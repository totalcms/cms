<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Repository;

/**
 * Short-lived OAuth state that must outlive the request that wrote it:
 * authorization codes between consent and exchange, revoked access-token
 * ids, and rotated refresh-token hashes.
 *
 * This state used to live in the cache, which is the wrong home for it. Dev
 * mode skips every cache read, so each code read as revoked. Any cache clear
 * (every settings save runs one) wiped it, so revoked tokens worked again and
 * replays went unseen. And on hosts whose APCu is not shared between PHP
 * workers, a code stored during consent was missing from the worker that
 * handled the exchange a second later.
 *
 * Each entry is one file, `{dir}/{bucket}/{sha256(key)}.json`, holding the
 * value and its expiry. Writes go to a temporary file renamed into place, so
 * a reader never sees half an entry. The key is hashed, so no code or token
 * id appears in a file name. Expired entries read as absent and are removed
 * when read or by pruneExpired(), which the daily OAuth sweep runs.
 */
final readonly class OAuthStateStore
{
	public function __construct(
		private string $directory,
	) {
	}

	/**
	 * Keep $value under $key for $ttl seconds. The value must encode as JSON.
	 */
	public function put(string $bucket, string $key, mixed $value, int $ttl): void
	{
		$dir = $this->bucketDir($bucket);
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException(sprintf('Could not create the OAuth state folder %s', $dir));
		}

		$json = json_encode(['expires' => time() + max(1, $ttl), 'value' => $value], JSON_THROW_ON_ERROR);
		$tmp  = tempnam($dir, '.tmp-');
		if ($tmp === false || file_put_contents($tmp, $json) === false || !rename($tmp, $this->path($bucket, $key))) {
			if (is_string($tmp) && is_file($tmp)) {
				unlink($tmp);
			}

			throw new \RuntimeException(sprintf('Could not write OAuth state in %s', $dir));
		}
	}

	/**
	 * The value under $key, or null when there is none or it has expired.
	 */
	public function get(string $bucket, string $key): mixed
	{
		$path  = $this->path($bucket, $key);
		$entry = $this->read($path);
		if ($entry === null) {
			return null;
		}

		if ($entry['expires'] < time()) {
			$this->remove($path);

			return null;
		}

		return $entry['value'];
	}

	public function delete(string $bucket, string $key): void
	{
		$this->remove($this->path($bucket, $key));
	}

	/**
	 * Remove every expired entry, and any file that is not a readable entry.
	 *
	 * @return int The number of files removed
	 */
	public function pruneExpired(): int
	{
		$removed = 0;
		$now     = time();
		foreach (glob($this->directory . '/*/*.json') ?: [] as $path) {
			$entry = $this->read($path);
			if ($entry === null || $entry['expires'] < $now) {
				$this->remove($path);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * @return array{expires: int, value: mixed}|null
	 */
	private function read(string $path): ?array
	{
		if (!is_file($path)) {
			return null;
		}

		$raw   = file_get_contents($path);
		$entry = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($entry) || !is_int($entry['expires'] ?? null) || !array_key_exists('value', $entry)) {
			return null;
		}

		return ['expires' => $entry['expires'], 'value' => $entry['value']];
	}

	private function remove(string $path): void
	{
		if (is_file($path)) {
			unlink($path);
		}
	}

	private function path(string $bucket, string $key): string
	{
		return $this->bucketDir($bucket) . '/' . hash('sha256', $key) . '.json';
	}

	private function bucketDir(string $bucket): string
	{
		if (preg_match('/^[a-z][a-z-]*$/', $bucket) !== 1) {
			throw new \InvalidArgumentException(sprintf('Invalid OAuth state bucket "%s"', $bucket));
		}

		return rtrim($this->directory, '/') . '/' . $bucket;
	}
}

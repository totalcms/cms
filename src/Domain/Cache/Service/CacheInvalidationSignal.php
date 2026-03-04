<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Support\Config;

/**
 * File-based signal for cross-process cache invalidation.
 *
 * When CLI processes (e.g., processJobs.php) clear cache keys, APCu and other
 * per-SAPI caches in the web process are not affected. This class writes
 * invalidated keys to a signal file that web-process middleware can replay.
 */
class CacheInvalidationSignal
{
	private readonly string $signalFile;

	public function __construct(
		private readonly Config $config,
	) {
		$this->signalFile = $this->config->datadir . '/.system/.cache_invalidate';
		$dir = dirname($this->signalFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
	}

	/**
	 * Record a single cache key for cross-process invalidation.
	 */
	public function signal(string $key): void
	{
		file_put_contents($this->signalFile, $key . "\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * Record a pattern-based invalidation (e.g., "domainprefix:computed:*").
	 */
	public function signalPattern(string $pattern): void
	{
		file_put_contents($this->signalFile, 'pattern:' . $pattern . "\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * Record a full cache clear signal.
	 */
	public function signalFull(): void
	{
		file_put_contents($this->signalFile, "full\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * Check if a signal file exists (cheap file_exists check).
	 */
	public function hasSignal(): bool
	{
		return file_exists($this->signalFile);
	}

	/**
	 * Read and delete the signal file, returning the list of invalidation entries.
	 *
	 * @return array<int,string>|null List of keys/commands, or null if no signal
	 */
	public function consume(): ?array
	{
		if (!file_exists($this->signalFile)) {
			return null;
		}

		$content = file_get_contents($this->signalFile);
		@unlink($this->signalFile);

		if ($content === false || $content === '') {
			return null;
		}

		$lines = array_unique(array_filter(
			array_map('trim', explode("\n", $content)),
			static fn (string $line): bool => $line !== ''
		));

		// If a full clear is present, nothing else matters
		if (in_array('full', $lines, true)) {
			return ['full'];
		}

		return array_values($lines);
	}
}

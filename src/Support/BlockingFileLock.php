<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Serializes a critical section across requests with a blocking exclusive
 * flock. The lock is released in a finally. If the lock file cannot be opened
 * the callback still runs, unlocked: losing serialization beats failing the
 * request.
 */
final class BlockingFileLock
{
	/**
	 * @template T
	 *
	 * @param callable():T $fn
	 *
	 * @return T
	 */
	public static function run(string $path, callable $fn): mixed
	{
		$dir = dirname($path);
		if (!is_dir($dir)) {
			QuietCall::run(fn () => mkdir($dir, 0700, true));
		}

		$handle = QuietCall::run(fn () => fopen($path, 'c'));
		if ($handle === false) {
			return $fn();
		}

		flock($handle, LOCK_EX);

		try {
			return $fn();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}

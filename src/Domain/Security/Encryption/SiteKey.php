<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Encryption;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Deployment-specific secret for Cipher::encrypt()/decrypt().
 *
 * Total CMS source is publicly readable, so the hardcoded Cipher::SALT
 * constant can never be a real secret. This class provides a per-site key,
 * generated once and stored at `<datadir>/.system/site.key` — inside the
 * data directory so it survives updates and travels with pull/push syncs
 * (both ends of a sync must share the key for encrypted URLs to work).
 *
 * Returns null when no data directory exists yet (pre-setup) or the key
 * can neither be read nor created — callers fall back to Cipher::SALT,
 * which is the pre-3.5 behavior.
 */
final class SiteKey
{
	private const FILENAME = 'site.key';

	private static ?string $cached = null;
	private static bool $resolved  = false;

	public static function get(): ?string
	{
		if (!self::$resolved) {
			self::$cached   = self::load();
			self::$resolved = true;
		}

		return self::$cached;
	}

	/**
	 * Drop the per-process memoization. Test hook — production code never
	 * needs this because the key file is immutable once created.
	 */
	public static function reset(): void
	{
		self::$cached   = null;
		self::$resolved = false;
	}

	private static function load(): ?string
	{
		$datadir = Config::init()->datadir;
		if ($datadir === '' || !is_dir($datadir)) {
			return null;
		}

		$systemDir = PathUtils::absolutePath($datadir, '.system');
		$file      = $systemDir . '/' . self::FILENAME;

		if (is_file($file)) {
			$key = trim((string)file_get_contents($file));

			return $key !== '' ? $key : null;
		}

		if (!is_dir($systemDir) && !@mkdir($systemDir, 0755, true) && !is_dir($systemDir)) {
			return null;
		}

		$key = bin2hex(random_bytes(32));

		// Exclusive create so a concurrent first request can't overwrite a
		// key another process just generated — the loser reads the winner's.
		$handle = @fopen($file, 'x');
		if ($handle === false) {
			$existing = trim((string)@file_get_contents($file));

			return $existing !== '' ? $existing : null;
		}

		fwrite($handle, $key . "\n");
		fclose($handle);
		@chmod($file, 0600);

		return $key;
	}
}

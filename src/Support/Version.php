<?php

namespace TotalCMS\Support;

class Version
{
	private static ?string $version = null;

	/**
	 * Get the full version string (e.g., "3.0.47-baee5e0e").
	 */
	public static function get(): string
	{
		if (self::$version !== null) {
			return self::$version;
		}

		$file = __DIR__ . '/../../version.txt';

		if (file_exists($file)) {
			$content = file_get_contents($file);
			if ($content !== false) {
				self::$version = trim($content);

				return self::$version;
			}
		}

		self::$version = 'unknown';

		return self::$version;
	}

	/**
	 * Get just the semantic version number (e.g., "3.0.47").
	 */
	public static function number(): string
	{
		$version = self::get();

		// Extract version from "3.0.47-baee5e0e" format
		if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
			return $matches[1];
		}

		return '3.0.0';
	}

	/**
	 * Get formatted version for Sentry releases (e.g., "totalcms@3.0.47-baee5e0e").
	 */
	public static function formatted(): string
	{
		return 'totalcms@' . self::get();
	}
}

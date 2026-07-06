<?php

namespace TotalCMS\Infrastructure\Filesystem;

use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Path Utilities.
 */
class PathUtils
{
	public static function cleanString(string $string): string
	{
		return SlugData::slugify($string);
	}

	/**
	 * Build path to file.
	 */
	public static function buildPath(
		string $collection,
		?string $objectID = null,
		?string $property = null,
		?string $filename = null,
		?string $subpath = null,
	): string {
		$path = self::cleanString($collection);

		if (isset($objectID)) {
			$path = sprintf('%s/%s', $path, self::cleanString($objectID));
		}
		if (isset($property)) {
			$path = sprintf('%s/%s', $path, self::cleanString($property));
		}
		if ($subpath !== null && $subpath !== '') {
			$path = sprintf('%s/%s', $path, self::sanitizeSubpath($subpath));
		}
		if ($filename !== null && $filename !== '') {
			$path = sprintf('%s/%s', $path, $filename);
		}

		return $path;
	}

	/**
	 * Join a storage-relative path onto an absolute base (typically the data
	 * dir), normalizing slashes. Use when you need a real filesystem path — for
	 * `require`, `is_file`, lock files, etc. — rather than the Flysystem-relative
	 * path that `buildPath()` produces.
	 */
	public static function absolutePath(string $base, string $relative): string
	{
		return rtrim($base, '/\\') . '/' . ltrim($relative, '/\\');
	}

	/**
	 * Sanitize subpath segments to prevent directory traversal attacks.
	 */
	public static function sanitizeSubpath(string $subpath): string
	{
		$subpath = str_replace('\\', '/', $subpath);
		$subpath = str_replace('..', '', $subpath);

		return trim($subpath, '/');
	}

	/**
	 * Split a slashed path into `[filename, subpath]`. The last segment is the
	 * filename; anything before it (joined by `/`) is the subpath. Path is
	 * sanitized first via {@see self::sanitizeSubpath()}, and the filename is
	 * URL-decoded (covers `+` → space, which path segments do *not* decode
	 * automatically — needed for filenames originally encoded by depot URLs).
	 *
	 * @return array{0:string, 1:?string}
	 */
	public static function splitPath(string $path): array
	{
		$path = self::sanitizeSubpath($path);
		if ($path === '') {
			return ['', null];
		}
		$pos = strrpos($path, '/');
		if ($pos === false) {
			return [self::decodeFilename($path), null];
		}

		return [
			self::decodeFilename(substr($path, $pos + 1)),
			substr($path, 0, $pos),
		];
	}

	/**
	 * URL-decode a filename segment, including the form-encoding `+` → space
	 * that path segments don't decode automatically.
	 */
	public static function decodeFilename(string $filename): string
	{
		return str_replace('+', ' ', urldecode($filename));
	}

	/**
	 * Reduce an uploaded filename to a filesystem- and URL-safe form: only
	 * `[A-Za-z0-9._-]` survive; every other run of characters (spaces, `&`,
	 * accented/exotic unicode, etc.) collapses to a single underscore.
	 *
	 * Exotic characters (e.g. U+2017, U+00BA) are handled inconsistently across
	 * the upload → filesystem → URL pipeline — the browser, PHP, macOS's on-disk
	 * normalization and URL-encoding don't always agree, so the stored name, the
	 * file on disk and the imageworks URL can diverge and 404. Normalizing to a
	 * plain-ASCII name at write time keeps all three identical.
	 */
	public static function safeFilename(string $filename): string
	{
		$filename = basename($filename);

		$ext  = pathinfo($filename, PATHINFO_EXTENSION);
		$name = pathinfo($filename, PATHINFO_FILENAME);

		// Transliterate to ASCII first (café → cafe, Москва → Moskva) so names
		// stay readable, then strip anything still outside the safe set.
		$name = self::transliterate($name);

		$name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
		$name = (string)preg_replace('/_{2,}/', '_', $name);
		$name = trim($name, '._-');

		if ($name === '') {
			$name = 'file';
		}

		$ext = (string)preg_replace('/[^A-Za-z0-9]+/', '', $ext);

		return $ext !== '' ? $name . '.' . $ext : $name;
	}

	/**
	 * Best-effort transliteration of Unicode text to ASCII (é → e, ü → u,
	 * non-Latin scripts to Latin where possible). Prefers the intl
	 * Transliterator, falls back to iconv, and returns the input unchanged if
	 * neither is available — {@see self::safeFilename()} still strips whatever
	 * survives, so a safe result is guaranteed either way.
	 */
	private static function transliterate(string $value): string
	{
		if ($value === '') {
			return $value;
		}

		if (class_exists(\Transliterator::class)) {
			$translit = \Transliterator::create('Any-Latin; Latin-ASCII');
			if ($translit !== null) {
				$result = $translit->transliterate($value);
				if (is_string($result)) {
					return $result;
				}
			}
		}

		if (function_exists('iconv')) {
			$result = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if ($result !== false) {
				return $result;
			}
		}

		return $value;
	}
}

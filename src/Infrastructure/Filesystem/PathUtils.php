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
}

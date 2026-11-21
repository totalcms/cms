<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Storage;

/**
 * Where files go in the bucket, and which stored keys this install may touch.
 *
 *     {prefix}{collection}/{objectId}/{property}/{id}-{slug}
 *     {prefix}{folder}/{id}-{slug}                when the property sets "folder"
 *
 * Keys read back from stored data are untrusted. Serving a key needs
 * isInsidePrefix(); deleting one needs isOwnedBy().
 */
final readonly class KeyBuilder
{
	private const SLUG_MAX = 80;

	/** @param string $prefix '' or 'segment/…/', as S3Settings cleans it */
	public function __construct(private string $prefix)
	{
	}

	/** One path segment: `[A-Za-z0-9._-]` kept, anything else a dash; '' for a segment that is only dots. */
	public static function segment(string $segment): string
	{
		$clean = (string)preg_replace('/[^A-Za-z0-9._-]/', '-', trim($segment));

		return trim($clean, '.') === '' ? '' : $clean;
	}

	/** A file name as lowercase ASCII: the base slugged to `[a-z0-9-]`, plus a short alphanumeric extension. */
	public static function slug(string $filename): string
	{
		$filename  = basename(str_replace('\\', '/', $filename));
		$dot       = strrpos($filename, '.');
		$base      = $dot === false || $dot === 0 ? $filename : substr($filename, 0, $dot);
		$extension = $dot === false || $dot === 0 ? '' : strtolower(substr($filename, $dot + 1));

		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-');
		$slug = trim(substr($slug, 0, self::SLUG_MAX), '-');
		$slug = $slug === '' ? 'file' : $slug;

		return preg_match('/^[a-z0-9]{1,10}\z/', $extension) === 1 ? $slug . '.' . $extension : $slug;
	}

	public static function newId(): string
	{
		return bin2hex(random_bytes(4));
	}

	/**
	 * The key for a new upload. A replaced file gets a new id, so a CDN never serves the old body.
	 *
	 * @throws \InvalidArgumentException when the collection, object id or property cannot be a path segment
	 */
	public function build(string $collection, string $objectId, string $property, string $filename, ?string $folder, ?string $id = null): string
	{
		$dir = $this->folderPath($folder);
		if ($dir === null) {
			$segments = array_map(self::segment(...), [$collection, $objectId, $property]);
			if (in_array('', $segments, true)) {
				throw new \InvalidArgumentException('Cannot build a bucket key for this object.');
			}
			$dir = implode('/', $segments) . '/';
		}

		return $this->prefix . $dir . ($id ?? self::newId()) . '-' . self::slug($filename);
	}

	/** The path every file of this object's property lives under; null when the property uses a folder. */
	public function ownedPrefix(string $collection, string $objectId, string $property, ?string $folder): ?string
	{
		if ($this->folderPath($folder) !== null) {
			return null;
		}

		$segments = array_map(self::segment(...), [$collection, $objectId, $property]);

		return in_array('', $segments, true) ? null : $this->prefix . implode('/', $segments) . '/';
	}

	/** Well-formed and under this install's prefix. Required before a key is served. */
	public function isInsidePrefix(string $key): bool
	{
		if ($key === '' || $key[0] === '/' || !str_starts_with($key, $this->prefix) || strlen($key) === strlen($this->prefix)) {
			return false;
		}

		foreach (explode('/', $key) as $segment) {
			if ($segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}

	/** Under this object's own path. Required before a key is deleted right away. */
	public function isOwnedBy(string $key, string $collection, string $objectId, string $property, ?string $folder): bool
	{
		$owned = $this->ownedPrefix($collection, $objectId, $property, $folder);

		return $owned !== null && $this->isInsidePrefix($key) && str_starts_with($key, $owned);
	}

	/** The 8-hex id a key's file name starts with. */
	public static function idFromKey(string $key): ?string
	{
		return preg_match('/^([0-9a-f]{8})-/', basename($key), $match) === 1 ? $match[1] : null;
	}

	/** 'a/b/' for a folder setting with at least one usable segment; null otherwise. */
	private function folderPath(?string $folder): ?string
	{
		$segments = array_filter(array_map(self::segment(...), explode('/', (string)$folder)), fn (string $s): bool => $s !== '');

		return $segments === [] ? null : implode('/', $segments) . '/';
	}
}

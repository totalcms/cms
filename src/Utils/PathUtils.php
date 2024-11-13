<?php

namespace TotalCMS\Utils;

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
	 *
	 * @param string $collection
	 * @param ?string $objectID
	 * @param ?string $property
	 * @param ?string $filename
	 * @param ?string $subpath
	 *
	 * @return string
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
		if (!empty($filename)) {
			if (!empty($subpath)) {
				$path = sprintf('%s/%s', $path, $subpath);
			}
			$path = sprintf('%s/%s', $path, $filename);
		}

		return $path;
	}
}

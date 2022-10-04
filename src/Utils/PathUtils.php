<?php

namespace App\Utils;

use Cocur\Slugify\Slugify;

/**
 * Path Utilities.
 */
class PathUtils
{
    public static function cleanString(string $string): string
    {
        return (new Slugify())->slugify($string);
    }

    /**
     * Build path to file.
     *
     * @param string $collection
     * @param ?string $objectID
     * @param ?string $property
     * @param ?string $filename
     *
     * @return string
     */
    public static function buildPath(
        string $collection,
        ?string $objectID = null,
        ?string $property = null,
        ?string $filename = null,
    ): string {
        $path = self::cleanString($collection);

        if (isset($objectID)) {
            $path = sprintf('%s/%s', $path, self::cleanString($objectID));
        }
        if (isset($property)) {
            $path = sprintf('%s/%s', $path, self::cleanString($property));
        }
        if (isset($filename)) {
            $path = sprintf('%s/%s', $path, $filename);
        }

        return $path;
    }
}

<?php

namespace TotalCMS\Utils\Faker;

use Faker\Provider\Base;

class FakerPicsum extends Base
{
    public const JPG_IMAGE  = 'jpg';
    public const WEBP_IMAGE = 'webp';

    /** @var array<string> */
    private static array $extensions = [self::JPG_IMAGE, self::WEBP_IMAGE];

    /**
     * @param int $width
     * @param int $height
     * @param bool $gray
     * @param int $blur
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public static function picsumUrl(int $width = 640, int $height = 480, bool $gray = false, int $blur = 0): string
    {
        $url  = '';
        $url .= "{$width}/{$height}";
        $queryString = self::buildQueryString($gray, $blur, true);

        return self::buildpicsumUrl($url, $queryString, 'jpg');
    }

    /**
     * Download a remote random image to disk and return its location.
     *
     * Requires curl, or allow_url_fopen to be on in php.ini.
     *
     * @example '/path/to/dir/13b73edae8443990be1aa8f1a483bc27.jpg'
     *
     * @param string $dir
     * @param int $width
     * @param int $height
     * @param bool $gray
     * @param int $blur
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public static function picsum(?string $dir = null, int $width = 640, int $height = 480, bool $gray = false, int $blur = 0): string
    {
        $url = static::picsumUrl($width, $height, $gray, $blur);

        return self::fetchImage($url, $dir);
    }

    /**
     * @param bool $gray
     * @param int $blur
     * @param bool $randomize
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    private static function buildQueryString(bool $gray = false, int $blur = 0, bool $randomize = false): string
    {
        $queryParams = [];
        $queryString = '';

        if ($gray === true) {
            $queryParams['grayscale'] = '';
        }

        if ($blur > 0) {
            $queryParams['blur'] = '';
        }

        if ($randomize === true) {
            $queryParams['random'] = static::randomNumber(5, true);
        }

        if (!empty($queryParams)) {
            $queryString = '?' . http_build_query($queryParams);
        }

        return $queryString;
    }

    /**
     * @param string $path
     * @param string $queryString
     * @param ?string $imageExtension
     */
    private static function buildPicsumUrl(string $path, string $queryString, ?string $imageExtension = null): string
    {
        $baseUrl = 'https://picsum.photos/';

        if ($imageExtension !== null) {
            if (!in_array($imageExtension, self::$extensions, true)) {
                throw new \InvalidArgumentException(sprintf('Invalid image extension "%s"', $imageExtension));
            }
            $path .= '.' . $imageExtension;
        }

        return $baseUrl . $path . $queryString;
    }

    /**
     * Download a remote random image to disk and return its location.
     *
     * Requires curl, or allow_url_fopen to be on in php.ini.
     *
     * @param string $url Image url to fetch
     * @param string|null $dir Directory where downloaded image will be stored
     */
    private static function fetchImage(string $url, ?string $dir = null): string
    {
        // Default to system temp dir
        $dir = empty($dir) ? sys_get_temp_dir() : $dir;

        // Validate directory path
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \InvalidArgumentException(sprintf('Cannot write to directory "%s"', $dir));
        }

        // Generate a random filename. Use the server address so that a file
        // generated at the same time on a different server won't have a collision.
        $filename = uniqid('picsum-', true) . '.jpg';
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;

        // save file
        if (!function_exists('curl_exec')) {
            throw new \RuntimeException('The image formatter downloads an image from a remote HTTP server. Therefore, it requires that PHP can request remote hosts, either via cURL or fopen()');
        }

        // use cURL
        $fp = fopen($filepath, 'w');
        if ($fp === false) {
            throw new \RuntimeException('The image formatter was unable to write to the file ' . $filepath);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('The image formatter was unable to download the remote image ' . $url);
        }
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TotalCMS/3.0');
        $success = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        fclose($fp);
        curl_close($ch);

        if (!$success) {
            unlink($filepath);
            throw new \RuntimeException('The image formatter was unable to download the remote image ' . $url);
        }

        return $filepath;
    }
}

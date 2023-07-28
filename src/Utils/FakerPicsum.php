<?php

namespace TotalCMS\Utils;

use Faker\Provider\Base;

class FakerPicsum extends Base
{
    public const JPG_IMAGE  = 'jpg';
    public const WEBP_IMAGE = 'webp';

    private static array $IMAGEEXTENSIONS = [self::JPG_IMAGE, self::WEBP_IMAGE];

    public static function picsumUrl($width = 640, $height = 480, $id = null, $randomize = true, $gray = false, $blur = null, $imageExtension = null)
    {
        $url = '';
        if ($id) {
            $url = 'id/' . $id . '/';
        }
        $url .= "{$width}/{$height}";
        $queryString = self::buildQueryString($gray, $blur, $randomize);

        return self::buildPicsumUrl($url, $queryString, $imageExtension);
    }

    public static function picsumStaticRandomUrl($width = 640, $height = 480, $gray = false, $blur = null, $imageExtension = null)
    {
        $url         = 'seed/' . uniqid() . '/' . "{$width}/{$height}";
        $queryString = self::buildQueryString($gray, $blur, null);

        return self::buildPicsumUrl($url, $queryString, $imageExtension);
    }

    /**
     * Download a remote random image to disk and return its location.
     *
     * Requires curl, or allow_url_fopen to be on in php.ini.
     *
     * @example '/path/to/dir/13b73edae8443990be1aa8f1a483bc27.jpg'
     *
     * @param mixed|null $dir
     * @param mixed $width
     * @param mixed $height
     * @param mixed $fullPath
     * @param mixed|null $id
     * @param mixed $randomize
     * @param mixed $gray
     * @param mixed|null $blur
     * @param mixed|null $imageExtension
     */
    public static function picsum($dir = null, $width = 640, $height = 480, $gray = false, $blur = false): string
    {
        $url = static::picsumUrl($width, $height, null, true, $gray, $blur, 'jpg');

        return self::fetchImage($url, $dir, true);
    }

    /**
     * @param bool|null $gray
     * @param int|null $blur
     * @param bool|null $randomize
     *
     * @return string
     */
    private static function buildQueryString($gray, $blur, $randomize)
    {
        $queryParams = [];
        $queryString = '';

        if ($gray) {
            $queryParams['grayscale'] = '';
        }

        if ($blur) {
            $queryParams['blur'] = '';
        }

        if ($randomize) {
            $queryParams['random'] = static::randomNumber(5, true);
        }

        if (!empty($queryParams)) {
            $queryString = '?' . http_build_query($queryParams);
        }

        return $queryString;
    }

    private static function buildPicsumUrl($path, $queryString, $imageExtension = null)
    {
        $baseUrl = 'https://picsum.photos/';

        if ($imageExtension) {
            if (!in_array($imageExtension, self::$IMAGEEXTENSIONS, true)) {
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
     * @param bool $fullPath Return full path to file or only filename
     *
     * @return bool|\RuntimeException|string
     */
    private static function fetchImage($url, $dir = null, $fullPath = true)
    {
        // Default to system temp dir
        $dir = is_null($dir) ? sys_get_temp_dir() : $dir;

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
        $ch = curl_init($url);
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

        return $fullPath ? $filepath : $filename;
    }
}

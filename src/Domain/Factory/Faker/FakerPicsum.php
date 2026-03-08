<?php

namespace TotalCMS\Domain\Factory\Faker;

use Faker\Provider\Base;
use TotalCMS\Support\CurlHttpClient;
use TotalCMS\Support\HttpClientInterface;

class FakerPicsum extends Base
{
	private static ?HttpClientInterface $httpClient = null;

	/**
	 * Set the HTTP client (for testing).
	 */
	public static function setHttpClient(?HttpClientInterface $client): void
	{
		self::$httpClient = $client;
	}

	private static function getHttpClient(): HttpClientInterface
	{
		return self::$httpClient ?? new CurlHttpClient();
	}

	public const JPG_IMAGE  = 'jpg';
	public const WEBP_IMAGE = 'webp';

	/** @var array<string> */
	private static array $extensions = [self::JPG_IMAGE, self::WEBP_IMAGE];

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public static function picsumUrl(int $width = 640, int $height = 480, bool $gray = false, int $blur = 0): string
	{
		$url  = '';
		$url .= "{$width}/{$height}";
		$queryString = self::buildQueryString($gray, $blur, false);

		return self::buildPicsumUrl($url, $queryString, 'jpg');
	}

	/**
	 * Download a remote random image to disk and return its location.
	 *
	 * Requires curl, or allow_url_fopen to be on in php.ini.
	 *
	 * @example '/path/to/dir/13b73edae8443990be1aa8f1a483bc27.jpg'
	 *
	 * @param string $dir
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public static function picsum(?string $dir = null, int $width = 640, int $height = 480, bool $gray = false, int $blur = 0): string
	{
		$url = static::picsumUrl($width, $height, $gray, $blur);

		return self::fetchImage($url, $dir);
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	private static function buildQueryString(bool $gray = false, int $blur = 0, bool $randomize = false): string
	{
		$queryParams = [];
		$queryString = '';

		if ($gray) {
			$queryParams['grayscale'] = '';
		}

		if ($blur > 0) {
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
		$dir = $dir === null || $dir === '' ? sys_get_temp_dir() : $dir;

		// Validate directory path
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new \InvalidArgumentException(sprintf('Cannot write to directory "%s"', $dir));
		}

		// Generate a random filename. Use the server address so that a file
		// generated at the same time on a different server won't have a collision.
		$filename = uniqid('picsum-', true) . '.jpg';
		$filepath = $dir . DIRECTORY_SEPARATOR . $filename;

		// Download using HTTP client
		try {
			$response = self::getHttpClient()->request('GET', $url, [
				'timeout'          => 10,
				'connect_timeout'  => 5,
				'follow_redirects' => true,
				'user_agent'       => 'TotalCMS/3.0',
			]);
		} catch (\RuntimeException) {
			throw new \RuntimeException('The image formatter was unable to download the remote image ' . $url);
		}

		if ($response->statusCode !== 200) {
			throw new \RuntimeException('The image formatter was unable to download the remote image ' . $url);
		}

		$bytesWritten = file_put_contents($filepath, $response->body);
		if ($bytesWritten === false) {
			throw new \RuntimeException('The image formatter was unable to write to the file ' . $filepath);
		}

		return $filepath;
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Fetch a URL into the configured temp directory.
 *
 * Every path that turns a remote URL into a local file — the admin's
 * upload-from-URL, the RSS and WordPress importers — goes through here, so
 * the safety posture is decided once: certificates are always verified, the
 * response is capped at `maxDownloadSize`, redirects are bounded, and the
 * filename is derived from the URL and sanitised. Two importers used to carry
 * their own copies with verification switched off and no cap at all.
 */
final readonly class RemoteFileDownloader
{
	private const DEFAULT_USER_AGENT = 'TotalCMS File Downloader';

	public function __construct(
		private HttpClientInterface $httpClient,
		private Config $config,
	) {
	}

	/**
	 * @param array{timeout?: int, user_agent?: string, prefix?: string} $options
	 *   `prefix` names the temp file `{prefix}-{unique}.{ext}` instead of
	 *   keeping the URL's own filename.
	 *
	 * @throws \RuntimeException when the download fails or exceeds the size cap
	 *
	 * @return string absolute path of the downloaded file
	 */
	public function download(string $url, array $options = []): string
	{
		if (!is_dir($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		$prefix   = $options['prefix'] ?? null;
		$filename = $prefix === null ? self::filenameFor($url) : self::uniqueFilenameFor($url, $prefix);
		$path     = $this->config->tmpdir . '/' . $filename;

		// 0 = unlimited
		$maxBytes = $this->config->maxDownloadSize > 0
			? $this->config->maxDownloadSize * 1024 * 1024
			: 0;

		try {
			$response = $this->httpClient->request('GET', $url, [
				'timeout'          => $options['timeout'] ?? 30,
				'follow_redirects' => 5,
				'user_agent'       => $options['user_agent'] ?? self::DEFAULT_USER_AGENT,
				'verify_ssl'       => true,
				'max_bytes'        => $maxBytes,
			]);
		} catch (\RuntimeException $e) {
			if ($maxBytes > 0 && str_contains($e->getMessage(), 'maximum size')) {
				throw new \RuntimeException('File exceeds maximum download size of ' . $this->config->maxDownloadSize . ' MB', $e->getCode(), $e);
			}

			throw new \RuntimeException('Failed to download file from URL: ' . $e->getMessage(), $e->getCode(), $e);
		}

		if ($response->statusCode !== 200) {
			throw new \RuntimeException('HTTP error when downloading file: ' . $response->statusCode);
		}

		if (file_put_contents($path, $response->body) === false) {
			throw new \RuntimeException('Failed to save downloaded file to: ' . $path);
		}

		return $path;
	}

	/**
	 * The URL's own filename, sanitised to `[A-Za-z0-9._-]`; a generated
	 * `.tmp` name when the URL has no filename with an extension.
	 */
	public static function filenameFor(string $url): string
	{
		$path     = parse_url($url, PHP_URL_PATH);
		$filename = basename(rawurldecode(is_string($path) ? $path : ''));

		if ($filename === '' || pathinfo($filename, PATHINFO_EXTENSION) === '') {
			return 'downloaded_file_' . uniqid() . '.tmp';
		}

		return (string)preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
	}

	/**
	 * `{prefix}-{unique}.{ext}`, with the extension taken from the URL and
	 * `jpg` when it has none — the shape the importers have always used.
	 */
	public static function uniqueFilenameFor(string $url, string $prefix): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$ext  = pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION);
		$ext  = $ext !== '' ? (string)preg_replace('/[^a-zA-Z0-9]/', '', $ext) : 'jpg';

		return $prefix . '-' . uniqid() . '.' . ($ext !== '' ? $ext : 'jpg');
	}
}

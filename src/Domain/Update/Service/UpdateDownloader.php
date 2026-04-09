<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

/**
 * Downloads update zip files from the license server.
 */
readonly class UpdateDownloader
{
	public function __construct(
		private HttpClientInterface $httpClient,
		private Config $config,
	) {
	}

	/**
	 * Download an update zip to a temporary file.
	 *
	 * @return string Path to the downloaded zip file
	 */
	public function download(string $version, string $downloadUrl): string
	{
		$licenseUrl = $this->getLicenseApiUrl();
		$fullUrl    = $licenseUrl . $downloadUrl;
		$tempPath   = $this->config->cachedir . "/update-{$version}.zip";

		$response = $this->httpClient->request('GET', $fullUrl, [
			'headers' => [
				'X-License-Domain: ' . $this->config->domain,
				'X-License-Version: ' . \TotalCMS\Support\Version::number(),
			],
			'timeout'          => 300,
			'follow_redirects' => 5,
			'sink'             => $tempPath,
			'user_agent'       => 'TotalCMS/' . \TotalCMS\Support\Version::number(),
		]);

		if ($response->statusCode >= 400) {
			if (file_exists($tempPath)) {
				unlink($tempPath);
			}
			throw new \RuntimeException("Download failed (HTTP {$response->statusCode})");
		}

		if (!file_exists($tempPath) || filesize($tempPath) === 0) {
			if (file_exists($tempPath)) {
				unlink($tempPath);
			}
			throw new \RuntimeException('Downloaded file is empty or missing');
		}

		// Verify it's a valid zip
		$zip = new \ZipArchive();
		if ($zip->open($tempPath) !== true) {
			unlink($tempPath);
			throw new \RuntimeException('Downloaded file is not a valid zip archive');
		}
		$zip->close();

		return $tempPath;
	}

	private function getLicenseApiUrl(): string
	{
		return Config::LICENSE_API_URL;
	}
}

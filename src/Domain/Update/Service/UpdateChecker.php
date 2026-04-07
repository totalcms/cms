<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\Version;

/**
 * Checks for available updates from the license server.
 * Results are cached for 24 hours.
 */
readonly class UpdateChecker
{
	private const CACHE_KEY = 'update_check';
	private const CACHE_TTL = 86400; // 24 hours

	public function __construct(
		private HttpClientInterface $httpClient,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Check for available updates.
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function checkForUpdate(bool $forceRefresh = false): UpdateInfo
	{
		if (!$forceRefresh) {
			$cached = $this->cacheManager->getComputedData(self::CACHE_KEY);
			if (is_array($cached)) {
				return UpdateInfo::fromApiResponse($cached);
			}
		}

		$licenseUrl     = $this->getLicenseApiUrl();
		$currentVersion = Version::number();

		$response = $this->httpClient->request('GET', $licenseUrl . '/version/latest?current=' . urlencode($currentVersion), [
			'timeout'         => 10,
			'connect_timeout' => 5,
			'user_agent'      => 'TotalCMS/' . $currentVersion,
		]);

		if ($response->statusCode >= 400) {
			return new UpdateInfo(
				available: false, version: $currentVersion, releaseDate: '',
				severity: '', changelog: '', buildHash: '', downloadUrl: ''
			);
		}

		$data = json_decode($response->body, true);
		if (!is_array($data)) {
			return new UpdateInfo(
				available: false, version: $currentVersion, releaseDate: '',
				severity: '', changelog: '', buildHash: '', downloadUrl: ''
			);
		}

		$this->cacheManager->storeComputedData(self::CACHE_KEY, $data, self::CACHE_TTL);

		return UpdateInfo::fromApiResponse($data);
	}

	public function clearCache(): void
	{
		$this->cacheManager->clearComputedData(self::CACHE_KEY);
	}

	private function getLicenseApiUrl(): string
	{
		return Config::LICENSE_API_URL;
	}
}

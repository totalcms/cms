<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Service\LicenseValidator;
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
		private LicenseValidator $licenseValidator,
	) {
	}

	/**
	 * Check for available updates.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function checkForUpdate(bool $forceRefresh = false): UpdateInfo
	{
		if (!$forceRefresh) {
			$cached = $this->cacheManager->getComputedData(self::CACHE_KEY);
			if (is_array($cached)) {
				return UpdateInfo::fromApiResponse(
					$cached,
					$cached['updatesValid'] ?? true,
					$cached['updatesExpireDate'] ?? null,
				);
			}
		}

		$licenseUrl     = $this->getLicenseApiUrl();
		$currentVersion = Version::number();

		$response = $this->httpClient->request('GET', $licenseUrl . '/version?current=' . urlencode($currentVersion), [
			'timeout'         => 10,
			'connect_timeout' => 5,
			'user_agent'      => 'TotalCMS/' . $currentVersion,
		]);

		if ($response->statusCode >= 400) {
			return $this->noUpdate($currentVersion);
		}

		$data = json_decode($response->body, true);
		if (!is_array($data)) {
			return $this->noUpdate($currentVersion);
		}

		// Suppress updates when the offered version is not actually newer than what's running.
		// This handles the beta/prerelease case where the server doesn't know the local build is ahead.
		$offeredVersion = (string)($data['version'] ?? '');
		if ((bool)($data['available'] ?? false) && $offeredVersion !== '' && version_compare($offeredVersion, $currentVersion, '<=')) {
			$data['available'] = false;
		}

		// Check if this license qualifies for updates
		$licenseInfo       = $this->getLicenseInfo();
		$updatesValid      = $licenseInfo['valid'];
		$updatesExpireDate = $licenseInfo['expireDate'];

		// Patch updates are always allowed, even with expired updates
		$severity = (string)($data['severity'] ?? 'patch');
		if (!$updatesValid && $severity === 'patch') {
			$updatesValid = true;
		}

		$data['updatesValid']      = $updatesValid;
		$data['updatesExpireDate'] = $updatesExpireDate;

		$this->cacheManager->storeComputedData(self::CACHE_KEY, $data, self::CACHE_TTL);

		return UpdateInfo::fromApiResponse($data, $updatesValid, $updatesExpireDate);
	}

	public function clearCache(): void
	{
		$this->cacheManager->clearComputedData(self::CACHE_KEY);
	}

	/**
	 * @return array{valid: bool, expireDate: ?string}
	 */
	private function getLicenseInfo(): array
	{
		try {
			$license = $this->licenseValidator->validateLicense();

			return [
				'valid'      => $license->updatesValid,
				'expireDate' => $license->updatesExpireDate,
			];
		} catch (\Throwable) {
			return ['valid' => false, 'expireDate' => null];
		}
	}

	private function noUpdate(string $currentVersion): UpdateInfo
	{
		$licenseInfo = $this->getLicenseInfo();

		return new UpdateInfo(
			available: false,
			version: $currentVersion,
			releaseDate: '',
			severity: '',
			changelog: '',
			buildHash: '',
			downloadUrl: '',
			updatesValid: $licenseInfo['valid'],
			updatesExpireDate: $licenseInfo['expireDate'],
		);
	}

	private function getLicenseApiUrl(): string
	{
		return Config::LICENSE_API_URL;
	}
}

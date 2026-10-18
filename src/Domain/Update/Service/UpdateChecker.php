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
 * Results are cached for 24 hours. A failed check is cached as "no update"
 * for an hour, so an unreachable server costs one timeout, not one per page.
 */
readonly class UpdateChecker
{
	private const CACHE_KEY_PREFIX = 'update_check';
	private const CACHE_TTL        = 86400; // 24 hours
	private const FAILURE_TTL      = 3600;  // 1 hour

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
			$cached = $this->cacheManager->getComputedData($this->cacheKey());
			if (is_array($cached)) {
				return UpdateInfo::fromApiResponse(
					$cached,
					$cached['updatesValid'] ?? true,
					$cached['updatesExpireDate'] ?? null,
				);
			}
		}

		$licenseUrl     = Config::LICENSE_API_URL;
		$currentVersion = Version::number();

		try {
			$response = $this->httpClient->request('GET', $licenseUrl . '/version?current=' . urlencode($currentVersion), [
				'timeout'         => 5,
				'connect_timeout' => 2,
				'user_agent'      => 'TotalCMS/' . $currentVersion,
			]);
		} catch (\RuntimeException $e) {
			// Cache the miss, then let the caller report the error.
			$this->noUpdate($currentVersion);
			throw $e;
		}

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

		$this->cacheManager->storeComputedData($this->cacheKey(), $data, self::CACHE_TTL);

		return UpdateInfo::fromApiResponse($data, $updatesValid, $updatesExpireDate);
	}

	public function clearCache(): void
	{
		$this->cacheManager->clearComputedData($this->cacheKey());
	}

	/**
	 * Cache key scoped to the RUNNING version. Any version change — including
	 * manual or Composer updates that never run the cache-clearing updater —
	 * therefore gets a fresh answer on the next check instead of up to 24h of
	 * stale info fetched while the previous version was installed (an RC-2
	 * site kept offering rc.4 from cache after rc.5 had shipped). Keys for
	 * prior versions simply age out via the TTL.
	 */
	private function cacheKey(): string
	{
		return self::CACHE_KEY_PREFIX . '_' . Version::number();
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

	/**
	 * The answer for a failed check, cached for FAILURE_TTL so the next page
	 * load does not wait on the server again.
	 */
	private function noUpdate(string $currentVersion): UpdateInfo
	{
		$licenseInfo = $this->getLicenseInfo();

		$this->cacheManager->storeComputedData($this->cacheKey(), [
			'available'         => false,
			'version'           => $currentVersion,
			'updatesValid'      => $licenseInfo['valid'],
			'updatesExpireDate' => $licenseInfo['expireDate'],
		], self::FAILURE_TTL);

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
}

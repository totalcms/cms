<?php

namespace TotalCMS\Domain\License\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Support\Config;

/**
 * License validation service.
 */
readonly class LicenseValidator
{
	private const CACHE_KEY = 'license_validation';
	private const CACHE_TTL = 24 * 60 * 60; // 24 hours

	public function __construct(
		private Config $config,
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * Validate license for current domain and version.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function validateLicense(bool $forceRefresh = false): LicenseData
	{
		// Check cache first (unless force refresh)
		if (!$forceRefresh) {
			$cached = $this->getCachedLicense();
			if ($cached && $cached->isCacheValid()) {
				return $cached;
			}
		}

		try {
			// Make API call for fresh validation
			$licenseData = $this->callLicenseApi();

			// Cache the result
			$this->cacheLicense($licenseData);

			return $licenseData;
		} catch (\Exception $e) {
			// Try cached data as fallback, even if expired
			$cached = $this->getCachedLicense();
			if ($cached instanceof LicenseData) {
				return $cached;
			}

			// If no cache available and API fails, re-throw the exception
			// The middleware will handle this gracefully with read-only mode
			throw new LicenseException('License validation failed and no cached data available: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Get cached license data.
	 */
	private function getCachedLicense(): ?LicenseData
	{
		$cached = $this->cacheManager->getLicenseData(self::CACHE_KEY);

		return $cached instanceof LicenseData ? $cached : null;
	}

	/**
	 * Cache license data.
	 */
	private function cacheLicense(LicenseData $licenseData): void
	{
		$this->cacheManager->storeLicenseData(self::CACHE_KEY, $licenseData, self::CACHE_TTL);
	}

	/**
	 * Clear cached license data.
	 */
	public function clearCache(): void
	{
		$this->cacheManager->clearLicenseData(self::CACHE_KEY);
	}

	/**
	 * Call license validation API with auto trial creation.
	 */
	private function callLicenseApi(): LicenseData
	{
		$domain  = $this->config->domain;
		$version = $this->getCurrentVersion();

		$payload = [
			'domain'  => $domain,
			'version' => $version,
		];

		// Use auto_trial=true parameter - server will create trial if no license exists
		$response = $this->makeHttpRequest('/license/validate?auto_trial=true', $payload);

		// With auto_trial=true, response should always be valid (either license or trial)
		return LicenseData::fromApiResponse($response);
	}


	/**
	 * Get current CMS version from version.txt file.
	 */
	private function getCurrentVersion(): string
	{
		$versionFile = __DIR__ . '/../../../../version.txt';
		if (file_exists($versionFile)) {
			$content = file_get_contents($versionFile);
			if ($content !== false) {
				// Extract version from "3.0.39 (24a576e9)" format
				preg_match('/^(\d+\.\d+\.\d+)/', trim($content), $matches);

				return $matches[1] ?? '3.0.0';
			}
		}

		return '3.0.0'; // fallback version
	}

	/**
	 * Get API base URL based on environment.
	 */
	private function getApiBaseUrl(): string
	{
		return $this->config->env === 'dev'
			? 'https://license.totalcms.test'
			: 'https://license.totalcms.co';
	}

	/**
	 * Make HTTP request to license API using cURL.
	 *
	 * @param array<string,mixed> $payload
	 *
	 * @throws LicenseException
	 *
	 * @return array<string,mixed>
	 */
	private function makeHttpRequest(string $endpoint, array $payload): array
	{
		$url         = $this->getApiBaseUrl() . $endpoint;
		$jsonPayload = json_encode($payload);

		if ($jsonPayload === false) {
			throw new LicenseException('Failed to encode request payload');
		}

		$curl = curl_init();
		if ($curl === false) {
			throw new LicenseException('Failed to initialize cURL');
		}

		try {
			curl_setopt_array($curl, [
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $jsonPayload,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'User-Agent: Total CMS/' . $this->getCurrentVersion(),
				],
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT      => 'Total CMS/' . $this->getCurrentVersion(),
			]);

			$response  = curl_exec($curl);
			$httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$curlError = curl_error($curl);

			if ($response === false || $curlError !== '') {
				throw new LicenseException(
					'HTTP request failed: ' . ($curlError ?: 'Unknown cURL error')
				);
			}

			if (!is_string($response)) {
				throw new LicenseException('Invalid response type from cURL');
			}

			$decodedResponse = json_decode($response, true);

			if ($decodedResponse === null) {
				throw new LicenseException(
					'Invalid JSON response from license server. HTTP Code: ' . $httpCode
				);
			}

			// Check for API error responses
			if (isset($decodedResponse['error'])) {
				throw new LicenseException(
					$decodedResponse['error'],
					$decodedResponse['code'] ?? $httpCode
				);
			}

			// Check for HTTP error codes
			if ($httpCode >= 400) {
				throw new LicenseException(
					$decodedResponse['error'] ?? 'HTTP Error',
					$httpCode
				);
			}

			return $decodedResponse;
		} finally {
			curl_close($curl);
		}
	}
}

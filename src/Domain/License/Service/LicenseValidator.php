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

			// If no cache available, return development fallback
			return $this->createDevelopmentFallback($e);
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
	 * Call license validation API.
	 */
	private function callLicenseApi(): LicenseData
	{
		$domain  = $this->config->domain;
		$version = $this->getCurrentVersion();

		$payload = [
			'domain'  => $domain,
			'version' => $version,
		];

		$response = $this->makeHttpRequest('/license/validate', $payload);

		// If no license found, try to create trial
		if (!$response['valid'] && isset($response['code']) && $response['code'] === 404) {
			return $this->createTrial($domain);
		}

		return LicenseData::fromApiResponse($response);
	}

	/**
	 * Create trial for domain.
	 */
	private function createTrial(string $domain): LicenseData
	{
		$payload  = ['domain' => $domain];
		$response = $this->makeHttpRequest('/trial', $payload);

		// Handle new trial response format
		$isValid = ($response['valid'] ?? false) === 'true' || ($response['valid'] ?? false) === true;

		// Extract days remaining from message
		$daysRemaining = null;
		if (isset($response['message'])) {
			preg_match('/(\d+) days remaining/', (string)$response['message'], $matches);
			$daysRemaining = isset($matches[1]) ? (int)$matches[1] : null;
		}

		// Convert trial response to LicenseData format
		$licenseResponse = [
			'valid'                => $isValid,
			'edition'              => 'trial',
			'main_domain'          => $response['domain'] ?? $domain,
			'updates_valid'        => true,
			'updates_expire_date'  => null,
			'allowed_version'      => $this->getCurrentVersion(),
			'testing_domains'      => [],
			'message'              => $response['message'] ?? 'Trial created',
			'validation_token'     => $response['jwtToken'] ?? null,
			'dns_verified'         => true,
			'dns_record'           => null,
			'verification_token'   => null,
			'trial_active'         => $isValid,
			'trial_expires_date'   => $response['expires'] ?? null,
			'trial_days_remaining' => $daysRemaining,
		];

		return LicenseData::fromApiResponse($licenseResponse);
	}

	/**
	 * Create development fallback when API is unavailable.
	 */
	private function createDevelopmentFallback(\Exception $exception): LicenseData
	{
		// In development environment, provide a mock trial license
		if ($this->config->env === 'dev') {
			$licenseResponse = [
				'valid'                => true,
				'edition'              => 'trial',
				'main_domain'          => $this->config->domain,
				'updates_valid'        => true,
				'updates_expire_date'  => null,
				'allowed_version'      => $this->getCurrentVersion(),
				'testing_domains'      => [],
				'message'              => 'Development mode - API unavailable',
				'validation_token'     => null,
				'dns_verified'         => true,
				'dns_record'           => null,
				'verification_token'   => null,
				'trial_active'         => true,
				'trial_expires_date'   => date('Y-m-d\TH:i:s\Z', strtotime('+30 days')),
				'trial_days_remaining' => 30,
			];

			$fallback = LicenseData::fromApiResponse($licenseResponse);

			// Cache the fallback for a short time to avoid repeated errors
			$this->cacheLicense($fallback);

			return $fallback;
		}

		// In production, throw the original exception
		throw new LicenseException(
			'License validation failed and no cached data available: ' . $exception->getMessage(),
			$exception->getCode(),
			$exception
		);
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

<?php

namespace TotalCMS\Domain\License\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * License validation service.
 */
readonly class LicenseValidator
{
	private const JWT_SECRET = 'VwRmMdlSNBD1soVXlNklfzKTkXpU5Bnc4cAiQrCi3tvsHfVpz3L2XDrCxv3UImAj';

	public function __construct(
		private Config $config,
		private CacheManager $cacheManager,
		private ?OfflineLicenseValidator $offlineValidator = null,
	) {
	}

	/**
	 * Validate license for current domain and version.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function validateLicense(bool $forceRefresh = false): LicenseData
	{
		// Skip license validation for preview environment
		// This prevents rate limiting when many users preview simultaneously
		if ($this->isPreviewEnvironment()) {
			return LicenseData::preview($this->config->domain);
		}

		// Check for offline license first (takes precedence over online)
		$offlineLicense = $this->validateOfflineLicense();
		if ($offlineLicense !== null) {
			return $offlineLicense;
		}

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
	 * Check for valid offline license.
	 */
	private function validateOfflineLicense(): ?LicenseData
	{
		if ($this->offlineValidator === null) {
			return null;
		}

		return $this->offlineValidator->validate();
	}

	/**
	 * Check if an offline license file exists.
	 */
	public function hasOfflineLicense(): bool
	{
		return $this->offlineValidator !== null && $this->offlineValidator->hasOfflineLicense();
	}

	/**
	 * Get offline license details for display.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getOfflineLicenseDetails(): ?array
	{
		if ($this->offlineValidator === null) {
			return null;
		}

		return $this->offlineValidator->getDetails();
	}

	/**
	 * Get cached license data.
	 */
	private function getCachedLicense(): ?LicenseData
	{
		$cached = $this->cacheManager->getLicenseData(LicenseData::CACHE_KEY);

		if (!$cached instanceof LicenseData) {
			return null;
		}

		// Check for old cached objects missing new properties (e.g., dnsVerified)
		// Accessing an uninitialized typed property throws an Error
		try {
			$cached->toArray(); // This will throw if any property is uninitialized
		} catch (\Error) {
			// Clear invalid cache and return null to trigger fresh API call
			$this->clearCache();

			return null;
		}

		return $cached;
	}

	/**
	 * Cache license data.
	 */
	private function cacheLicense(LicenseData $licenseData): void
	{
		$this->cacheManager->storeLicenseData(LicenseData::CACHE_KEY, $licenseData, LicenseData::CACHE_STORAGE_TTL);
	}

	/**
	 * Clear cached license data.
	 */
	public function clearCache(): void
	{
		$this->cacheManager->clearLicenseData(LicenseData::CACHE_KEY);
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
	 * Get current CMS version.
	 */
	private function getCurrentVersion(): string
	{
		return Version::number();
	}

	/**
	 * Validate JWT token from license server.
	 *
	 * @throws LicenseException
	 */
	public function validateJwtToken(string $token): void
	{
		try {
			// Validate JWT token with shared secret
			$decoded = JWT::decode($token, new Key(self::JWT_SECRET, 'HS256'));

			// Basic token validation - expiresAt is in ISO format
			if (isset($decoded->expiresAt)) {
				$expiresAt = new \DateTime($decoded->expiresAt);
				if ($expiresAt < new \DateTime()) {
					throw new LicenseException('JWT token has expired');
				}
			} elseif (isset($decoded->exp) && $decoded->exp < time()) {
				// Fallback for old-style exp claim
				throw new LicenseException('JWT token has expired');
			}

			// JWT token validation passed
		} catch (\Exception $e) {
			throw new LicenseException('JWT token validation failed: ' . $e->getMessage());
		}
	}

	/**
	 * Check if running in preview environment.
	 */
	private function isPreviewEnvironment(): bool
	{
		return $this->config->env === 'preview';
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

		if ($url === '') {
			throw new LicenseException('Invalid license API URL');
		}

		if ($jsonPayload === false) {
			throw new LicenseException('Failed to encode request payload');
		}

		$curl = curl_init();
		if ($curl === false) {
			throw new LicenseException('Failed to initialize cURL');
		}

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

		// Note: curl_close() not needed - deprecated in PHP 8.5, no effect since PHP 8.0
		// Curl handles are automatically cleaned up when they go out of scope

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
			$errorMessage = is_array($decodedResponse['error'])
				? json_encode($decodedResponse['error'])
				: $decodedResponse['error'];
			throw new LicenseException(
				$errorMessage,
				$decodedResponse['code'] ?? $httpCode
			);
		}

		// Check for HTTP error codes
		if ($httpCode >= 400) {
			$errorMessage = 'HTTP Error';
			if (isset($decodedResponse['error'])) {
				$errorMessage = is_array($decodedResponse['error'])
					? json_encode($decodedResponse['error'])
					: $decodedResponse['error'];
			}
			throw new LicenseException($errorMessage, $httpCode);
		}

		return $decodedResponse;
	}
}

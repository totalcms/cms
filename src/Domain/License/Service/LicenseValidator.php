<?php

namespace TotalCMS\Domain\License\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\Version;

/**
 * License validation service.
 */
class LicenseValidator
{
	private const JWT_SECRET = 'VwRmMdlSNBD1soVXlNklfzKTkXpU5Bnc4cAiQrCi3tvsHfVpz3L2XDrCxv3UImAj';

	/** @var LicenseData|null In-memory cache for current request */
	private ?LicenseData $cachedResult = null;

	public function __construct(
		private readonly Config $config,
		private readonly CacheManager $cacheManager,
		private readonly HttpClientInterface $httpClient,
		private readonly ?OfflineLicenseValidator $offlineValidator = null,
	) {
	}

	/**
	 * Validate license for current domain and version.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function validateLicense(bool $forceRefresh = false): LicenseData
	{
		// Return in-memory cached result (unless force refresh)
		if (!$forceRefresh && $this->cachedResult instanceof LicenseData) {
			return $this->cachedResult;
		}

		// Skip license validation for preview environment
		// This prevents rate limiting when many users preview simultaneously
		if ($this->isPreviewEnvironment()) {
			$this->cachedResult = LicenseData::preview($this->config->domain);

			return $this->cachedResult;
		}

		// Check for offline license first (takes precedence over online)
		$offlineLicense = $this->validateOfflineLicense();
		if ($offlineLicense instanceof LicenseData) {
			$this->cachedResult = $offlineLicense;

			return $this->cachedResult;
		}

		// Check cache first (unless force refresh)
		if (!$forceRefresh) {
			$cached = $this->getCachedLicense();
			if ($cached && $cached->isCacheValid()) {
				$this->cachedResult = $cached;

				return $this->cachedResult;
			}
		}

		try {
			// Make API call for fresh validation
			$licenseData = $this->callLicenseApi();

			// Cache the result
			$this->cacheLicense($licenseData);
			$this->cachedResult = $licenseData;

			return $licenseData;
		} catch (\Exception $e) {
			// Try cached data as fallback, even if expired
			$cached = $this->getCachedLicense();
			if ($cached instanceof LicenseData) {
				$this->cachedResult = $cached;

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
		if (!$this->offlineValidator instanceof OfflineLicenseValidator) {
			return null;
		}

		return $this->offlineValidator->validate();
	}

	/**
	 * Check if an offline license file exists.
	 */
	public function hasOfflineLicense(): bool
	{
		return $this->offlineValidator instanceof OfflineLicenseValidator && $this->offlineValidator->hasOfflineLicense();
	}

	/**
	 * Get offline license details for display.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getOfflineLicenseDetails(): ?array
	{
		if (!$this->offlineValidator instanceof OfflineLicenseValidator) {
			return null;
		}

		return $this->offlineValidator->getDetails();
	}

	/**
	 * Get the expected filename for the offline license.
	 */
	public function getOfflineLicenseFilename(): ?string
	{
		if (!$this->offlineValidator instanceof OfflineLicenseValidator) {
			return null;
		}

		return $this->offlineValidator->getExpectedFilename();
	}

	/**
	 * Get the expected directory for the offline license.
	 */
	public function getOfflineLicenseDirectory(): ?string
	{
		if (!$this->offlineValidator instanceof OfflineLicenseValidator) {
			return null;
		}

		return $this->offlineValidator->getExpectedDirectory();
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
		$this->cachedResult = null;
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
			throw new LicenseException('JWT token validation failed: ' . $e->getMessage(), $e->getCode(), $e);
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
	 * Make HTTP request to license API.
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

		try {
			$response = $this->httpClient->request('POST', $url, [
				'body'              => $jsonPayload,
				'headers'           => [
					'Content-Type: application/json',
					'User-Agent: Total CMS/' . $this->getCurrentVersion(),
				],
				'timeout'           => 30,
				'connect_timeout'   => 10,
				'follow_redirects'  => 3,
				'verify_ssl'        => true,
				'user_agent'        => 'Total CMS/' . $this->getCurrentVersion(),
			]);
		} catch (\RuntimeException $e) {
			throw new LicenseException('HTTP request failed: ' . $e->getMessage(), 0, $e);
		}

		$decodedResponse = $response->json();

		if ($decodedResponse === null) {
			throw new LicenseException(
				'Invalid JSON response from license server. HTTP Code: ' . $response->statusCode
			);
		}

		// Check for API error responses
		if (isset($decodedResponse['error'])) {
			$errorMessage = is_array($decodedResponse['error'])
				? (string)json_encode($decodedResponse['error'])
				: $decodedResponse['error'];
			throw new LicenseException(
				$errorMessage,
				$decodedResponse['code'] ?? $response->statusCode
			);
		}

		// Check for HTTP error codes
		if ($response->statusCode >= 400) {
			$errorMessage = 'HTTP Error';
			if (isset($decodedResponse['error'])) {
				$errorMessage = is_array($decodedResponse['error'])
					? (string)json_encode($decodedResponse['error'])
					: $decodedResponse['error'];
			}
			throw new LicenseException($errorMessage, $response->statusCode);
		}

		return $decodedResponse;
	}
}

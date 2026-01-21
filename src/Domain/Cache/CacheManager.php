<?php

namespace TotalCMS\Domain\Cache;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Strategic cache manager that routes different data types to optimal cache services.
 */
class CacheManager
{
	// Cache key prefixes
	public const PREFIX_COMPUTED        = 'computed';
	public const PREFIX_COLLECTION      = 'collection';
	public const PREFIX_API_RESPONSE    = 'api';
	public const PREFIX_SESSION         = 'session';
	public const PREFIX_TEMPLATE        = 'template';
	public const PREFIX_PASSWORD_RESET  = 'password_reset';

	// Array of all cache types for clearByType functionality
	public const CACHE_TYPES = [
		self::PREFIX_COMPUTED,
		self::PREFIX_COLLECTION,
		self::PREFIX_API_RESPONSE,
		self::PREFIX_SESSION,
		self::PREFIX_TEMPLATE,
	];

	// Cache TTL constants for different data types
	// Increased TTLs for production performance - cache invalidation via .cache_version handles updates
	public const DEFAULT_TTL             = 7200;              // 2 hours - default TTL for most data
	public const TTL_COLLECTIONS_LIST    = 3600;      // 1 hour - collections rarely change in production
	public const TTL_INDEX_DATA          = 3600;           // 1 hour - indexes change when objects are added/removed
	public const TTL_OBJECT_IDS          = 1800;            // 30 minutes - changes when objects are added/removed
	public const TTL_OBJECT_DATA         = 14400;          // 4 hours - individual objects change infrequently
	public const TTL_RESERVED_SCHEMAS    = 86400;     // 24 hours - reserved schemas NEVER change
	public const TTL_RESERVED_SCHEMA_IDS = 86400;  // 24 hours - reserved schema IDs NEVER change
	public const TTL_CUSTOM_SCHEMA       = 14400;        // 4 hours - custom schemas change infrequently
	public const TTL_API_RESPONSE        = 1800;          // 30 minutes - API responses can be cached longer
	public const TTL_SESSION_DATA        = 1440;         // 24 minutes - session timeout buffer (unchanged)
	public const TTL_PASSWORD_RESET      = 1800;        // 30 minutes - password reset tokens (unchanged)

	private string $versionFile = '.cache_version';
	private readonly string $domainPrefix;
	private readonly LoggerInterface $logger;

	/** @var array<string,CacheInterface> Available cache services */
	private array $cacheServices = [];

	/**
	 * In-memory flag to bypass cache reads for current process.
	 * When true, getData() returns null, forcing fresh filesystem reads.
	 * Cache writes still occur to warm shared caches.
	 */
	private bool $cacheDisabled = false;

	public function __construct(
		private readonly FilesystemService $filesystemService,
		private readonly OPcacheService $opcacheService,
		private readonly RedisService $redisService,
		private readonly MemcachedService $memcachedService,
		private readonly APCuService $apcuService,
		private readonly WatermarkCleanupService $watermarkCleanupService,
		private readonly DevModeManager $devModeManager,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('cachemanager');
		// Initialize cache services and version
		$this->cacheServices = [
			'filesystem' => $this->filesystemService,
			'opcache'    => $this->opcacheService,
			'redis'      => $this->redisService,
			'memcached'  => $this->memcachedService,
			'apcu'       => $this->apcuService,
		];
		$this->versionFile  = $this->filesystemService->getCachDir() . '/' . $this->versionFile;

		// Create domain-specific prefix to prevent cache collisions between installations
		$this->domainPrefix = md5($this->config->domain);
	}

	/**
	 * Disable cache reads for the current process.
	 * Useful for CLI scripts that need fresh data on every read.
	 * This is in-memory only and does not affect other processes.
	 *
	 * Note: Cache writes still occur to warm shared caches (Redis, filesystem)
	 * with fresh data that the web server can use.
	 */
	public function disableCache(): void
	{
		$this->cacheDisabled = true;
	}

	/**
	 * Re-enable caching for the current process.
	 */
	public function enableCache(): void
	{
		$this->cacheDisabled = false;
	}

	/**
	 * Check if caching is currently disabled for this process.
	 */
	public function isCacheDisabled(): bool
	{
		return $this->cacheDisabled;
	}

	/**
	 * Create a domain-specific cache key to prevent collisions between installations.
	 */
	private function createDomainKey(string $key): string
	{
		return $this->domainPrefix . ':' . $key;
	}

	/**
	 * Store collection index data (fast access needed, can be large).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $index
	 */
	public function storeCollectionIndex(string $collectionName, array $index, int $ttl = self::TTL_INDEX_DATA): bool
	{
		$key = $this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}");

		return $this->storeData($key, $index, $ttl);
	}

	public function getCacheDirectory(): string
	{
		return $this->filesystemService->getCachDir();
	}

	/**
	 * Retrieve collection index data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getCollectionIndex(string $collectionName): ?array
	{
		return $this->getData($this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}"));
	}

	/**
	 * Store API response data (fast access, medium TTL).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $params
	 */
	public function storeApiResponse(string $endpoint, array $params, mixed $response, int $ttl = self::TTL_API_RESPONSE): bool
	{
		$key = $this->createDomainKey(self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params)));

		return $this->storeData($key, $response, $ttl);
	}

	/**
	 * Retrieve API response data.
	 *
	 * @param array<string,mixed> $params
	 */
	public function getApiResponse(string $endpoint, array $params): mixed
	{
		$key = $this->createDomainKey(self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params)));

		return $this->getData($key);
	}

	/** Store computed/expensive operations (can be large, longer TTL). */
	public function storeComputedData(string $key, mixed $data, int $ttl = self::TTL_CUSTOM_SCHEMA): bool
	{
		$cacheKey = $this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}");

		return $this->storeData($cacheKey, $data, $ttl);
	}

	public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// Note: We always store data even when cacheDisabled or devMode is active.
		// These flags only bypass reads - we still want to populate cache with fresh data
		// so that when the flags are turned off, the cache is warm and accurate.

		// Priority: APCu > Redis > Memcached > Filesystem (single cache layer only)
		if ($this->apcuService->isAvailable()) {
			return $this->apcuService->set($key, $data, $ttl);
		}

		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($key, $data, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($key, $data, $ttl);
		}

		// Fallback to filesystem cache only if no memory caches available
		if ($this->filesystemService->isAvailable()) {
			return $this->filesystemService->set($key, $data, $ttl);
		}

		return false;
	}

	public function getData(string $key): mixed
	{
		// Skip cache reads when cache is disabled or dev mode is active
		if ($this->cacheDisabled || $this->devModeManager->isDevModeActive()) {
			return null;
		}

		// Check memory caches first (fastest)
		if ($this->apcuService->isAvailable()) {
			$result = $this->apcuService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		// Check filesystem cache
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	public function clearData(string $key): bool
	{
		$success = true;

		// Delete from all available cache backends
		if ($this->apcuService->isAvailable()) {
			$success &= $this->apcuService->delete($key);
		}

		if ($this->redisService->isAvailable()) {
			$success &= $this->redisService->delete($key);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->memcachedService->delete($key);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->filesystemService->delete($key);
		}

		// Always clear OPcache to ensure no stale cached data
		if ($this->opcacheService->isAvailable()) {
			$success &= $this->opcacheService->clear();
		}

		return (bool)$success;
	}

	/**
	 * Retrieve computed data with cache warming.
	 */
	public function getComputedData(string $key): mixed
	{
		return $this->getData($this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}"));
	}

	/**
	 * Delete computed data from all cache backends.
	 */
	public function clearComputedData(string $key): bool
	{
		return $this->clearData($this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}"));
	}

	/**
	 * Clear all computed data (useful for clearing schema caches).
	 */
	public function clearAllComputedData(): bool
	{
		return $this->clearByType(self::PREFIX_COMPUTED);
	}

	/**
	 * Clear collection index cache from all backends.
	 */
	public function clearCollectionIndex(string $collectionName): bool
	{
		return $this->clearData($this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}"));
	}

	/**
	 * Store session data (fast access, APCu preferred for single-server deployments).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storeSessionData(string $sessionId, array $data, int $ttl = self::TTL_SESSION_DATA): bool
	{
		$key = $this->createDomainKey(self::PREFIX_SESSION . ":{$sessionId}");

		return $this->storeData($key, $data, $ttl);
	}

	/**
	 * Retrieve session data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSessionData(string $sessionId): ?array
	{
		return $this->getData($this->createDomainKey(self::PREFIX_SESSION . ":{$sessionId}"));
	}

	/**
	 * Store template compilation results (large files, should persist).
	 * Priority: Filesystem only (OPcache handles the compiled PHP automatically).
	 */
	public function storeCompiledTemplate(string $templateName, string $compiledCode): bool
	{
		// Skip caching entirely when development mode is active
		if ($this->devModeManager->isDevModeActive()) {
			return false;
		}

		if ($this->filesystemService->isAvailable()) {
			$key = $this->createDomainKey(self::PREFIX_TEMPLATE . ":{$templateName}");

			return $this->filesystemService->set($key, $compiledCode, 0); // No TTL for templates
		}

		return false;
	}

	/**
	 * Clear all cache entries of a specific type.
	 *
	 * @param string $type Cache type prefix (use CACHE_TYPES constants)
	 *
	 * @return bool Success status
	 */
	public function clearByType(string $type): bool
	{
		// Validate type
		if (!in_array($type, self::CACHE_TYPES, true)) {
			return false;
		}

		$success = true;
		$pattern = $this->domainPrefix . ':' . $type . ':*';

		// Clear from APCu
		if ($this->apcuService->isAvailable()) {
			$success &= $this->clearByPattern($this->apcuService, $pattern);
		}

		// Clear from Redis
		if ($this->redisService->isAvailable()) {
			$success &= $this->clearByPattern($this->redisService, $pattern);
		}

		// Clear from Memcached
		if ($this->memcachedService->isAvailable()) {
			$success &= $this->clearByPattern($this->memcachedService, $pattern);
		}

		// Clear from Filesystem
		if ($this->filesystemService->isAvailable()) {
			$success &= $this->clearByPattern($this->filesystemService, $pattern);
		}

		return (bool)$success;
	}

	/**
	 * Clear cache entries by pattern for a specific cache service.
	 */
	private function clearByPattern(CacheInterface $service, string $pattern): bool
	{
		// Check if the service supports pattern-based clearing
		if ($service instanceof RedisService || $service instanceof MemcachedService || $service instanceof FilesystemService || $service instanceof APCuService) {
			return $service->clearByPattern($pattern);
		}

		// For other services, fallback to clearing everything
		// This ensures cache is cleared when needed, though not optimal
		return $service->clear();
	}

	/**
	 * Set a new cache version (invalidates all caches).
	 */
	private function setCacheVersion(string $version): void
	{
		// Don't create version files when filesystem cache is not available
		if (!$this->filesystemService->isAvailable()) {
			return;
		}

		// Try to write version file
		file_put_contents($this->versionFile, $version);
	}

	/**
	 * Store license data - MANDATORY caching that cannot be disabled.
	 * License data doesn't change frequently and hitting license server on every request is bad for performance.
	 * This method bypasses all cache disabled settings to prevent rate limit cascades.
	 *
	 * IMPORTANT: License data is stored in BOTH memory cache AND filesystem.
	 * Memory cache provides fast access, filesystem provides persistent backup
	 * that survives memory cache eviction or server restarts.
	 */
	public function storeLicenseData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// License caching is MANDATORY - bypasses all cache disabled settings
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey    = $this->createDomainKey($key);
		$memoryStored = false;

		// Store in memory cache (fastest) - use isInstalled() to bypass config disabled checks
		// Try each memory cache in priority order until one succeeds
		if ($this->apcuService->isInstalled()) {
			$memoryStored = $this->apcuService->set($domainKey, $data, $ttl);
		}

		if (!$memoryStored && $this->redisService->isInstalled()) {
			$memoryStored = $this->redisService->set($domainKey, $data, $ttl);
		}

		if (!$memoryStored && $this->memcachedService->isInstalled()) {
			$memoryStored = $this->memcachedService->set($domainKey, $data, $ttl);
		}

		// ALWAYS store in filesystem as persistent backup
		// This ensures license data survives memory cache eviction or server restarts
		$filesystemStored = $this->filesystemService->set($domainKey, $data, $ttl);

		// Success if stored in at least one location
		return $memoryStored || $filesystemStored;
	}

	/**
	 * Get license data - MANDATORY caching that cannot be disabled.
	 * This method bypasses all cache disabled settings to prevent rate limit cascades.
	 */
	public function getLicenseData(string $key): mixed
	{
		// License caching is MANDATORY - bypasses all cache disabled settings
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);

		// Check memory caches first (fastest)
		// Use isInstalled() instead of isAvailable() to bypass config disabled checks
		if ($this->apcuService->isInstalled()) {
			$result = $this->apcuService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->redisService->isInstalled()) {
			$result = $this->redisService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->memcachedService->isInstalled()) {
			$result = $this->memcachedService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		// Filesystem is ALWAYS checked as absolute fallback for license data
		$result = $this->filesystemService->get($domainKey);

		return $result;
	}

	/**
	 * Clear license data - clears from all installed backends regardless of config.
	 */
	public function clearLicenseData(string $key): bool
	{
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);
		$success   = true;

		// Delete from all installed cache backends (bypasses config disabled checks)
		if ($this->apcuService->isInstalled()) {
			$success &= $this->apcuService->delete($domainKey);
		}

		if ($this->redisService->isInstalled()) {
			$success &= $this->redisService->delete($domainKey);
		}

		if ($this->memcachedService->isInstalled()) {
			$success &= $this->memcachedService->delete($domainKey);
		}

		// Always clear from filesystem
		$success &= $this->filesystemService->delete($domainKey);

		return (bool)$success;
	}

	/**
	 * Store password reset data - bypasses dev mode and cache clearing.
	 * Similar to license data, password reset tokens should persist regardless of dev mode
	 * and should NOT be cleared when users clear cache through the admin interface.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storePasswordResetData(string $key, array $data, int $ttl = self::TTL_PASSWORD_RESET): bool
	{
		// Always cache password reset data regardless of dev mode
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);

		// Priority: APCu > Redis > Memcached > Filesystem (single cache layer only)
		if ($this->apcuService->isAvailable()) {
			return $this->apcuService->set($domainKey, $data, $ttl);
		}

		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($domainKey, $data, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($domainKey, $data, $ttl);
		}

		// Fallback to filesystem cache only if no memory caches available
		if ($this->filesystemService->isAvailable()) {
			return $this->filesystemService->set($domainKey, $data, $ttl);
		}

		return false;
	}

	/**
	 * Get password reset data - bypasses dev mode.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getPasswordResetData(string $key): ?array
	{
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);

		// Check memory caches first (fastest)
		if ($this->apcuService->isAvailable()) {
			$result = $this->apcuService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		// Check filesystem cache
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Clear password reset data - bypasses dev mode.
	 */
	public function clearPasswordResetData(string $key): bool
	{
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);
		$success   = true;

		// Delete from all available cache backends
		if ($this->apcuService->isAvailable()) {
			$success &= $this->apcuService->delete($domainKey);
		}

		if ($this->redisService->isAvailable()) {
			$success &= $this->redisService->delete($domainKey);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->memcachedService->delete($domainKey);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->filesystemService->delete($domainKey);
		}

		return (bool)$success;
	}

	/**
	 * Clear all caches including OPcache and text watermark cache.
	 *
	 * @return array<string,mixed> Status information about what was cleared
	 */
	public function clearAllCaches(): array
	{
		$results        = [];
		$overallSuccess = true;

		// Preserve license data before clearing (critical for system operation)
		$licenseData = $this->getLicenseData(LicenseData::CACHE_KEY);

		// Clear all available cache services
		foreach ($this->cacheServices as $name => $service) {
			if (!$service->isAvailable()) {
				$results[$name] = ['cleared' => false, 'reason' => 'not available'];
				continue;
			}

			try {
				$cleared        = $service->clear();
				$results[$name] = ['cleared' => $cleared, 'reason' => $cleared ? 'success' : 'failed'];
				$overallSuccess = $overallSuccess && $cleared;
			} catch (\Exception $e) {
				$results[$name] = ['cleared' => false, 'reason' => $e->getMessage()];
				$overallSuccess = false;
			}
		}

		// Restore license data after clearing
		if ($licenseData instanceof LicenseData) {
			$this->storeLicenseData(LicenseData::CACHE_KEY, $licenseData, LicenseData::CACHE_STORAGE_TTL);
			$results['license'] = ['preserved' => true, 'reason' => 'restored after clear'];
		}

		// Clear text watermark cache (clear all cached watermarks)
		try {
			$this->watermarkCleanupService->clearOldCache(0); // Clear all watermarks regardless of age
			$results['watermarks'] = ['cleared' => true, 'reason' => 'success'];
		} catch (\Exception $e) {
			// Log error but don't fail the entire cache clear operation
			$this->logger->warning('Failed to clear text watermark cache', [
				'error'     => $e->getMessage(),
				'exception' => $e::class,
			]);
			$results['watermarks'] = ['cleared' => false, 'reason' => $e->getMessage()];
			$overallSuccess        = false;
		}

		// Generate new cache version
		$this->setCacheVersion(date('Y-m-d-H-i-s') . '-' . uniqid());
		$results['version'] = ['cleared' => true, 'reason' => 'new version generated'];

		$results['success'] = $overallSuccess;

		return $results;
	}
}

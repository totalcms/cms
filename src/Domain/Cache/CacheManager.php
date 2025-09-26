<?php

namespace TotalCMS\Domain\Cache;

use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Support\Config;

/**
 * Strategic cache manager that routes different data types to optimal cache services.
 */
class CacheManager
{
	// Cache key prefixes
	public const PREFIX_COMPUTED     = 'computed';
	public const PREFIX_COLLECTION   = 'collection';
	public const PREFIX_API_RESPONSE = 'api';
	public const PREFIX_SESSION      = 'session';
	public const PREFIX_TEMPLATE     = 'template';

	// Array of all cache types for clearByType functionality
	public const CACHE_TYPES = [
		self::PREFIX_COMPUTED,
		self::PREFIX_COLLECTION,
		self::PREFIX_API_RESPONSE,
		self::PREFIX_SESSION,
		self::PREFIX_TEMPLATE,
	];

	// Cache TTL constants for different data types
	public const DEFAULT_TTL             = 3600;              // 1 hour - default TTL for most data
	public const TTL_COLLECTIONS_LIST    = 900;      // 15 minutes - collections don't change often
	public const TTL_INDEX_DATA          = 1800;           // 30 minutes - indexes change when objects are added/removed
	public const TTL_OBJECT_IDS          = 900;            // 15 minutes - changes when objects are added/removed
	public const TTL_OBJECT_DATA         = 3600;          // 1 hour - individual objects change infrequently
	public const TTL_RESERVED_SCHEMAS    = 3600;     // 1 hour - reserved schemas never change
	public const TTL_RESERVED_SCHEMA_IDS = 3600;  // 1 hour - reserved schema IDs never change
	public const TTL_CUSTOM_SCHEMA       = 7200;        // 2 hours - custom schemas change infrequently
	public const TTL_API_RESPONSE        = 900;          // 15 minutes - API responses can be cached briefly
	public const TTL_SESSION_DATA        = 1440;         // 24 minutes - session timeout buffer

	private string $versionFile = '.cache_version';
	private readonly string $domainPrefix;

	/** @var array<string,CacheInterface> Available cache services */
	private array $cacheServices = [];

	public function __construct(
		private readonly FilesystemService $filesystemService,
		private readonly OPcacheService $opcacheService,
		private readonly RedisService $redisService,
		private readonly MemcachedService $memcachedService,
		private readonly APCuService $apcuService,
		private readonly TextWatermarkFactory $textWatermarkFactory,
		private readonly DevModeManager $devModeManager,
		private readonly Config $config,
	) {
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
		// Skip caching entirely when development mode is active
		if ($this->devModeManager->isDevModeActive()) {
			return false;
		}

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
	 * Store license data - bypasses dev mode since license validation should always be cached.
	 * License data doesn't change frequently and hitting license server on every request is bad for performance.
	 */
	public function storeLicenseData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// Always cache license data regardless of dev mode - performance is critical
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);

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
	 * Get license data - bypasses dev mode since license validation should always be cached.
	 */
	public function getLicenseData(string $key): mixed
	{
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);

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
	 * Clear license data - bypasses dev mode.
	 */
	public function clearLicenseData(string $key): bool
	{
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);
		$success = true;

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
	 */
	public function clearAllCaches(): bool
	{
		$success = true;

		// Clear all available cache services
		foreach ($this->cacheServices as $service) {
			if (!$service->isAvailable()) {
				continue; // Skip unavailable services
			}
			$success = $success && $service->clear();
		}

		// Clear text watermark cache (clear all cached watermarks)
		try {
			$this->textWatermarkFactory->clearOldCache(0); // Clear all watermarks regardless of age
		} catch (\Exception $e) {
			// Log error but don't fail the entire cache clear operation
			error_log('Failed to clear text watermark cache: ' . $e->getMessage());
			$success = false;
		}

		// Generate new cache version
		$this->setCacheVersion(date('Y-m-d-H-i-s') . '-' . uniqid());

		return $success;
	}
}

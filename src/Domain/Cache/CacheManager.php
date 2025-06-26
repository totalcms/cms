<?php

namespace TotalCMS\Domain\Cache;

use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;

/**
 * Strategic cache manager that routes different data types to optimal cache services.
 */
final class CacheManager
{
	private const NO_CACHE = 'dev-no-cache';

	// Cache key prefixes
	public const PREFIX_COMPUTED = 'computed';
	public const PREFIX_COLLECTION = 'collection';
	public const PREFIX_API_RESPONSE = 'api_response';
	public const PREFIX_SESSION = 'session';
	public const PREFIX_TEMPLATE = 'template';

	// Array of all cache types for clearByType functionality
	public const CACHE_TYPES = [
		self::PREFIX_COMPUTED,
		self::PREFIX_COLLECTION,
		self::PREFIX_API_RESPONSE,
		self::PREFIX_SESSION,
		self::PREFIX_TEMPLATE,
	];

	// Cache TTL constants for different data types
	public const DEFAULT_TTL = 3600;              // 1 hour - default TTL for most data
	public const TTL_COLLECTIONS_LIST = 900;      // 15 minutes - collections don't change often
	public const TTL_INDEX_DATA = 1800;           // 30 minutes - indexes change when objects are added/removed
	public const TTL_OBJECT_IDS = 900;            // 15 minutes - changes when objects are added/removed
	public const TTL_OBJECT_DATA = 3600;          // 1 hour - individual objects change infrequently
	public const TTL_RESERVED_SCHEMAS = 3600;     // 1 hour - reserved schemas never change
	public const TTL_RESERVED_SCHEMA_IDS = 3600;  // 1 hour - reserved schema IDs never change
	public const TTL_CUSTOM_SCHEMA = 7200;        // 2 hours - custom schemas change infrequently
	public const TTL_API_RESPONSE = 900;          // 15 minutes - API responses can be cached briefly
	public const TTL_SESSION_DATA = 1440;         // 24 minutes - session timeout buffer

	/** @var string Current cache version for invalidation */
	private string $cacheVersion;
	private string $versionFile = '.cache_version';

	/** @var array<string,CacheInterface> Available cache services */
	private array $cacheServices = [];

	public function __construct(
		private FilesystemService $filesystemService,
		private OPcacheService $opcacheService,
		private RedisService $redisService,
		private MemcachedService $memcachedService,
	) {
		// Initialize cache services and version
		$this->cacheServices = [
			'filesystem' => $this->filesystemService,
			'opcache'    => $this->opcacheService,
			'redis'      => $this->redisService,
			'memcached'  => $this->memcachedService,
		];
		$this->cacheVersion = $this->getCacheVersion();
		$this->versionFile  = $this->filesystemService->getCachDir() . '/' . $this->versionFile;
	}

	/**
	 * Store collection index data (fast access needed, can be large).
	 * Priority: Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $index
	 */
	public function storeCollectionIndex(string $collectionName, array $index, int $ttl = self::TTL_INDEX_DATA): bool
	{
		$key = self::PREFIX_COLLECTION . ":{$collectionName}";
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
		return $this->getData(self::PREFIX_COLLECTION . ":{$collectionName}");
	}

	/**
	 * Store API response data (fast access, medium TTL).
	 * Priority: Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $params
	 */
	public function storeApiResponse(string $endpoint, array $params, mixed $response, int $ttl = self::TTL_API_RESPONSE): bool
	{
		$key = self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params));
		return $this->storeData($key, $response, $ttl);
	}

	/**
	 * Retrieve API response data.
	 *
	 * @param array<string,mixed> $params
	 */
	public function getApiResponse(string $endpoint, array $params): mixed
	{
		$key = self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params));
		return $this->getData($key);
	}

	/** Store computed/expensive operations (can be large, longer TTL). */
	public function storeComputedData(string $key, mixed $data, int $ttl = self::TTL_CUSTOM_SCHEMA): bool
	{
		$cacheKey = self::PREFIX_COMPUTED . ":{$key}";
		return $this->storeData($cacheKey, $data, $ttl);
	}

	public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// Priority: Redis > Memcached > Filesystem (single cache layer only)
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
		if ($this->redisService->isAvailable()) {
			$success &= $this->redisService->delete($key);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->memcachedService->delete($key);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->filesystemService->delete($key);
		}

		return (bool) $success;
	}


	/**
	 * Retrieve computed data with cache warming.
	 */
	public function getComputedData(string $key): mixed
	{
		return $this->getData(self::PREFIX_COMPUTED . ":{$key}");
	}

	/**
	 * Delete computed data from all cache backends.
	 */
	public function clearComputedData(string $key): bool
	{
		return $this->clearData(self::PREFIX_COMPUTED . ":{$key}");
	}

	/**
	 * Clear collection index cache from all backends.
	 */
	public function clearCollectionIndex(string $collectionName): bool
	{
		return $this->clearData(self::PREFIX_COLLECTION . ":{$collectionName}");
	}

	/**
	 * Store session data (fast access, Redis preferred for distributed systems).
	 * Priority: Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storeSessionData(string $sessionId, array $data, int $ttl = self::TTL_SESSION_DATA): bool
	{
		$key = self::PREFIX_SESSION . ":{$sessionId}";
		return $this->storeData($key, $data, $ttl);
	}

	/**
	 * Retrieve session data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSessionData(string $sessionId): ?array
	{
		return $this->getData(self::PREFIX_SESSION . ":{$sessionId}");
	}

	/**
	 * Store template compilation results (large files, should persist).
	 * Priority: Filesystem only (OPcache handles the compiled PHP automatically).
	 */
	public function storeCompiledTemplate(string $templateName, string $compiledCode): bool
	{
		if ($this->filesystemService->isAvailable()) {
			$key = self::PREFIX_TEMPLATE . ":{$templateName}";

			return $this->filesystemService->set($key, $compiledCode, 0); // No TTL for templates
		}

		return false;
	}

	/**
	 * Clear all cache entries of a specific type.
	 *
	 * @param string $type Cache type prefix (use CACHE_TYPES constants)
	 * @return bool Success status
	 */
	public function clearByType(string $type): bool
	{
		// Validate type
		if (!in_array($type, self::CACHE_TYPES, true)) {
			return false;
		}

		$success = true;
		$pattern = $type . ':*';

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

		return (bool) $success;
	}

	/**
	 * Clear cache entries by pattern for a specific cache service.
	 * Note: This is a simplified implementation. Full pattern support would require
	 * each cache service to implement pattern-based deletion.
	 */
	private function clearByPattern(CacheInterface $service, string $pattern): bool
	{
		// For now, we fallback to the service's clear() method which clears everything
		// This is not ideal but ensures cache is cleared when needed
		// TODO: Implement proper pattern-based clearing in each cache service
		return $service->clear();
	}

	/**
	 * Get cache statistics across all services.
	 *
	 * @return array<string,mixed>
	 */
	public function getUsageStats(): array
	{
		return [
			'filesystem' => $this->filesystemService->getStats(),
			'opcache'    => $this->opcacheService->getStats(),
			'redis'      => $this->redisService->getStats(),
			'memcached'  => $this->memcachedService->getStats(),
		];
	}

	/**
	 * Get recommended cache configuration based on usage patterns.
	 *
	 * @return array<string>
	 */
	public function getStrategicRecommendations(): array
	{
		return $this->buildRecommendations();
	}

	/**
	 * Build cache recommendations based on available services.
	 *
	 * @return array<string>
	 */
	private function buildRecommendations(): array
	{
		$recommendations = [];
		$services        = $this->getServiceAvailability();

		$this->addCriticalRecommendations($recommendations, $services);
		$this->addOptimizationRecommendations($recommendations, $services);
		$this->addStatusRecommendations($recommendations, $services);

		return $recommendations;
	}

	/**
	 * Get availability status for all cache services.
	 *
	 * @return array<string,bool>
	 */
	private function getServiceAvailability(): array
	{
		return [
			'opcache'    => $this->opcacheService->isAvailable(),
			'memory'     => $this->redisService->isAvailable() || $this->memcachedService->isAvailable(),
			'filesystem' => $this->filesystemService->isAvailable(),
			'redis'      => $this->redisService->isAvailable(),
			'memcached'  => $this->memcachedService->isAvailable(),
		];
	}

	/**
	 * Add critical recommendations.
	 *
	 * @param array<string> $recommendations
	 * @param array<string,bool> $services
	 */
	private function addCriticalRecommendations(array &$recommendations, array $services): void
	{
		if (!$services['opcache']) {
			$recommendations[] = '🚨 CRITICAL: Enable OPcache for 2-5x performance improvement';
		}
	}

	/**
	 * Add optimization recommendations.
	 *
	 * @param array<string> $recommendations
	 * @param array<string,bool> $services
	 */
	private function addOptimizationRecommendations(array &$recommendations, array $services): void
	{
		if (!$services['memory']) {
			$recommendations[] = '⚡ HIGH: Enable Redis or Memcached for fast API/session caching';
		}

		if (!$services['filesystem']) {
			$recommendations[] = '💾 MEDIUM: Enable filesystem cache for persistent template storage';
		}

		if ($services['redis'] && $services['memcached']) {
			$recommendations[] = '💡 TIP: You have both Redis and Memcached - consider disabling one to reduce complexity';
		}
	}

	/**
	 * Add status recommendations.
	 *
	 * @param array<string> $recommendations
	 * @param array<string,bool> $services
	 */
	private function addStatusRecommendations(array &$recommendations, array $services): void
	{
		if ($services['memory'] && $services['filesystem'] && $services['opcache']) {
			$recommendations[] = '✅ EXCELLENT: You have optimal multi-tier cache setup';
		}
	}

	/**
	 * Get the current cache version for invalidation.
	 */
	private function getCacheVersion(): string
	{
		// Don't create version files when filesystem cache is not available
		if (!$this->filesystemService->isAvailable()) {
			return self::NO_CACHE;
		}

		if (file_exists($this->versionFile)) {
			$content = file_get_contents($this->versionFile);

			return $content !== false ? $content : self::NO_CACHE;
		}

		// Generate new version
		$version = date('Y-m-d-H-i-s') . '-' . uniqid();
		$this->setCacheVersion($version);

		return $version;
	}

	/**
	 * Set a new cache version (invalidates all caches).
	 */
	private function setCacheVersion(string $version): void
	{
		$this->cacheVersion = $version;

		// Don't create version files when filesystem cache is not available
		if (!$this->filesystemService->isAvailable()) {
			return;
		}

		// Try to write version file
		file_put_contents($this->versionFile, $version);
	}

	/**
	 * Clear all caches including OPcache.
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

		// Generate new cache version
		$this->setCacheVersion(date('Y-m-d-H-i-s') . '-' . uniqid());

		return $success;
	}

	/**
	 * Get cache statistics and health information.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheStats(): array
	{
		/** @var array<string,mixed> $stats */
		$stats = [
			'cache_enabled'      => $this->hasAnyCacheService(),
			'available_backends' => [],
			'cache_version'      => $this->cacheVersion,
		];

		foreach ($this->cacheServices as $key => $service) {
			$serviceStats = $service->getStats();
			$stats[$key]  = $serviceStats;

			if ($serviceStats['available'] ?? false) {
				$stats['available_backends'][$key] = $service->getName();
			}
		}

		return $stats;
	}

	/**
	 * Get optimal cache configuration recommendations.
	 *
	 * @return array<string,mixed>
	 */
	public function getOptimalCacheConfig(): array
	{
		$config = [
			'cache_dir'       => $this->filesystemService->getCachDir(),
			'recommendations' => [],
		];

		foreach ($this->cacheServices as $service) {
			$config['recommendations'] = array_merge(
				$config['recommendations'],
				$service->getRecommendations()
			);
		}

		return $config;
	}

	/**
	 * Check if any cache service is available.
	 */
	private function hasAnyCacheService(): bool
	{
		foreach ($this->cacheServices as $service) {
			if ($service->isAvailable()) {
				return true;
			}
		}

		return false;
	}
}

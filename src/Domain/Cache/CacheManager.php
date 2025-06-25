<?php

namespace TotalCMS\Domain\Cache;

use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Cache\Service\MemcachedService;

/**
 * Strategic cache manager that routes different data types to optimal cache services.
 */
final class CacheManager
{
	private const NO_CACHE = 'dev-no-cache';

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
	 * Priority: Redis > Memcached > Filesystem
	 *
	 * @param array<string,mixed> $index
	 */
	public function storeCollectionIndex(string $collectionName, array $index, int $ttl = 3600): bool
	{
		$key = "collection_index:{$collectionName}";

		// Try Redis first (best for structured data)
		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($key, $index, $ttl);
		}

		// Fallback to Memcached
		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($key, $index, $ttl);
		}

		// Last resort: Filesystem (with longer TTL since it's persistent)
		if ($this->filesystemService->isAvailable()) {
			return $this->filesystemService->set($key, $index, $ttl * 24); // 24x longer for file cache
		}

		return false;
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
		$key = "collection_index:{$collectionName}";

		// Check Redis first
		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		// Check Memcached
		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($key);
			if ($result !== null) {
				// Populate Redis with the data for next time
				if ($this->redisService->isAvailable()) {
					$this->redisService->set($key, $result, 3600);
				}
				return $result;
			}
		}

		// Check Filesystem
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($key);
			if ($result !== null) {
				// Populate memory caches for next time
				if ($this->redisService->isAvailable()) {
					$this->redisService->set($key, $result, 3600);
				} elseif ($this->memcachedService->isAvailable()) {
					$this->memcachedService->set($key, $result, 3600);
				}
				return $result;
			}
		}

		return null;
	}

	/**
	 * Store API response data (fast access, medium TTL).
	 * Priority: Redis > Memcached > Filesystem
	 *
	 * @param array<string,mixed> $params
	 */
	public function storeApiResponse(string $endpoint, array $params, mixed $response, int $ttl = 900): bool
	{
		$key = "api_response:" . md5($endpoint . serialize($params));

		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($key, $response, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($key, $response, $ttl);
		}

		// API responses shouldn't use filesystem cache (too slow for API responses)
		return false;
	}

	/**
	 * Retrieve API response data.
	 *
	 * @param array<string,mixed> $params
	 */
	public function getApiResponse(string $endpoint, array $params): mixed
	{
		$key = "api_response:" . md5($endpoint . serialize($params));

		if ($this->redisService->isAvailable()) {
			return $this->redisService->get($key);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->get($key);
		}

		return null;
	}

	/**
	 * Store computed/expensive operations (can be large, longer TTL).
	 * Priority: Filesystem > Redis > Memcached
	 */
	public function storeComputedData(string $key, mixed $data, int $ttl = 7200): bool
	{
		$cacheKey = "computed:{$key}";

		// Filesystem first for computed data (often large and should persist)
		if ($this->filesystemService->isAvailable()) {
			$success = $this->filesystemService->set($cacheKey, $data, $ttl);

			// Also store in memory cache for faster access (shorter TTL)
			if ($this->redisService->isAvailable()) {
				$this->redisService->set($cacheKey, $data, min($ttl, 1800)); // Max 30 min in memory
			} elseif ($this->memcachedService->isAvailable()) {
				$this->memcachedService->set($cacheKey, $data, min($ttl, 1800));
			}

			return $success;
		}

		// Fallback to memory caches
		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($cacheKey, $data, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($cacheKey, $data, $ttl);
		}

		return false;
	}

	/**
	 * Retrieve computed data with cache warming.
	 */
	public function getComputedData(string $key): mixed
	{
		$cacheKey = "computed:{$key}";

		// Check memory caches first (fastest)
		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($cacheKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($cacheKey);
			if ($result !== null) {
				return $result;
			}
		}

		// Check filesystem cache
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($cacheKey);
			if ($result !== null) {
				// Warm memory caches
				if ($this->redisService->isAvailable()) {
					$this->redisService->set($cacheKey, $result, 1800);
				} elseif ($this->memcachedService->isAvailable()) {
					$this->memcachedService->set($cacheKey, $result, 1800);
				}
				return $result;
			}
		}

		return null;
	}

	/**
	 * Store session data (fast access, Redis preferred for distributed systems).
	 * Priority: Redis > Memcached (Filesystem not suitable for sessions)
	 *
	 * @param array<string,mixed> $data
	 */
	public function storeSessionData(string $sessionId, array $data, int $ttl = 1440): bool
	{
		$key = "session:{$sessionId}";

		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($key, $data, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($key, $data, $ttl);
		}

		// Sessions should not use filesystem cache (security + performance)
		return false;
	}

	/**
	 * Retrieve session data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSessionData(string $sessionId): ?array
	{
		$key = "session:{$sessionId}";

		if ($this->redisService->isAvailable()) {
			return $this->redisService->get($key);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->get($key);
		}

		return null;
	}

	/**
	 * Store template compilation results (large files, should persist).
	 * Priority: Filesystem only (OPcache handles the compiled PHP automatically)
	 */
	public function storeCompiledTemplate(string $templateName, string $compiledCode): bool
	{
		if ($this->filesystemService->isAvailable()) {
			$key = "template:{$templateName}";
			return $this->filesystemService->set($key, $compiledCode, 0); // No TTL for templates
		}

		return false;
	}

	/**
	 * Clear cache by data type.
	 * Note: Pattern-based clearing not yet implemented in individual services.
	 */
	public function clearByType(string $type): bool
	{
		$validTypes = ['collections', 'api', 'computed', 'sessions', 'templates'];

		if (!in_array($type, $validTypes, true)) {
			return false;
		}

		// TODO: Implement pattern-based clearing in each service
		// For now, this method serves as a placeholder for future implementation
		return true;
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
			'opcache' => $this->opcacheService->getStats(),
			'redis' => $this->redisService->getStats(),
			'memcached' => $this->memcachedService->getStats(),
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
		$services = $this->getServiceAvailability();

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
			'opcache' => $this->opcacheService->isAvailable(),
			'memory' => $this->redisService->isAvailable() || $this->memcachedService->isAvailable(),
			'filesystem' => $this->filesystemService->isAvailable(),
			'redis' => $this->redisService->isAvailable(),
			'memcached' => $this->memcachedService->isAvailable(),
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

	// ===========================================
	// General Cache Management (formerly TwigCacheManager functionality)
	// ===========================================

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
			$stats[$key] = $serviceStats;

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
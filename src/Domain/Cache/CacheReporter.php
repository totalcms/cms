<?php

namespace TotalCMS\Domain\Cache;

use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;

/**
 * Cache reporting and analysis service.
 * Provides statistics, recommendations, and performance analysis for cache system.
 */
final class CacheReporter
{
	public function __construct(
		private FilesystemService $filesystemService,
		private OPcacheService $opcacheService,
		private RedisService $redisService,
		private MemcachedService $memcachedService,
	) {
	}

	/**
	 * Get usage statistics for cache optimization decisions.
	 *
	 * @return array<string,mixed>
	 */
	public function getUsageStats(): array
	{
		return [
			'redis_available'      => $this->redisService->isAvailable(),
			'memcached_available'  => $this->memcachedService->isAvailable(),
			'filesystem_available' => $this->filesystemService->isAvailable(),
			'opcache_available'    => $this->opcacheService->isAvailable(),
			'preferred_backend'    => $this->getPreferredBackend(),
			'cache_directory'      => $this->filesystemService->getCachDir(),
		];
	}

	/**
	 * Get strategic recommendations for cache setup optimization.
	 *
	 * @return array<string>
	 */
	public function getStrategicRecommendations(): array
	{
		$recommendations = [];
		$services        = [
			'redis'      => $this->redisService->isAvailable(),
			'memcached'  => $this->memcachedService->isAvailable(),
			'filesystem' => $this->filesystemService->isAvailable(),
			'opcache'    => $this->opcacheService->isAvailable(),
			'memory'     => $this->redisService->isAvailable() || $this->memcachedService->isAvailable(),
		];

		// Add recommendations based on available services
		$this->addCriticalRecommendations($recommendations, $services);
		$this->addOptimizationRecommendations($recommendations, $services);
		$this->addStatusRecommendations($recommendations, $services);

		return $recommendations;
	}

	/**
	 * Get comprehensive cache statistics from all backends.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheStats(): array
	{
		$stats = [
			'timestamp'          => time(),
			'cache_enabled'      => $this->isCacheEnabled(),
			'cache_version'      => '2.0', // Current cache system version
			'available_backends' => $this->getAvailableBackends(),
			'backend_status'     => $this->getBackendStatus(),
			'services'           => [],
		];

		// Collect stats from each available service
		if ($this->redisService->isAvailable()) {
			$stats['services']['redis'] = $this->redisService->getStats();
		}

		if ($this->memcachedService->isAvailable()) {
			$stats['services']['memcached'] = $this->memcachedService->getStats();
		}

		if ($this->filesystemService->isAvailable()) {
			$stats['services']['filesystem'] = $this->filesystemService->getStats();
		}

		if ($this->opcacheService->isAvailable()) {
			$stats['services']['opcache'] = $this->opcacheService->getStats();
		}

		return $stats;
	}

	/**
	 * Get optimal cache configuration recommendations based on current setup.
	 *
	 * @return array<string,mixed>
	 */
	public function getOptimalCacheConfig(): array
	{
		$config = [
			'recommended_strategy' => $this->getRecommendedStrategy(),
			'ttl_recommendations'  => $this->getTTLRecommendations(),
			'backend_priorities'   => $this->getBackendPriorities(),
			'performance_tips'     => $this->getPerformanceTips(),
		];

		return $config;
	}

	/**
	 * Add critical recommendations for missing essential cache services.
	 *
	 * @param array<string> $recommendations
	 * @param array<string,bool> $services
	 */
	private function addCriticalRecommendations(array &$recommendations, array $services): void
	{
		if (!$services['memory']) {
			$recommendations[] = '🚨 CRITICAL: Install Redis or Memcached for better performance';
		}

		if (!$services['opcache']) {
			$recommendations[] = '⚠️ WARNING: Enable OPcache for PHP performance improvements';
		}

		if (!$services['filesystem']) {
			$recommendations[] = '⚠️ WARNING: Filesystem cache unavailable - check permissions';
		}
	}

	/**
	 * Add optimization recommendations based on current setup.
	 *
	 * @param array<string> $recommendations
	 * @param array<string,bool> $services
	 */
	private function addOptimizationRecommendations(array &$recommendations, array $services): void
	{
		if ($services['redis'] && $services['memcached']) {
			$recommendations[] = '💡 TIP: You have both Redis and Memcached - Redis is preferred';
		}

		if ($services['memory'] && !$services['filesystem']) {
			$recommendations[] = '💡 TIP: Enable filesystem cache as fallback when memory cache fails';
		}

		if ($services['filesystem'] && !$services['memory']) {
			$recommendations[] = '🚀 PERFORMANCE: Add Redis/Memcached for significant speed improvements';
		}
	}

	/**
	 * Add status recommendations showing current setup quality.
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
	 * Get the preferred cache backend based on availability.
	 */
	private function getPreferredBackend(): string
	{
		if ($this->redisService->isAvailable()) {
			return 'redis';
		}

		if ($this->memcachedService->isAvailable()) {
			return 'memcached';
		}

		if ($this->filesystemService->isAvailable()) {
			return 'filesystem';
		}

		return 'none';
	}

	/**
	 * Get recommended caching strategy based on available services.
	 */
	private function getRecommendedStrategy(): string
	{
		$hasMemory     = $this->redisService->isAvailable() || $this->memcachedService->isAvailable();
		$hasFilesystem = $this->filesystemService->isAvailable();

		if ($hasMemory && $hasFilesystem) {
			return 'multi_tier'; // Memory + Filesystem fallback
		}

		if ($hasMemory) {
			return 'memory_only'; // Redis/Memcached only
		}

		if ($hasFilesystem) {
			return 'filesystem_only'; // File cache only
		}

		return 'no_cache'; // No caching available
	}

	/**
	 * Get TTL recommendations for different data types.
	 *
	 * @return array<string,int>
	 */
	private function getTTLRecommendations(): array
	{
		return [
			'short_lived_data'    => 300,   // 5 minutes (API responses, volatile data)
			'medium_lived_data'   => 1800,  // 30 minutes (collection indexes, computed data)
			'long_lived_data'     => 7200,  // 2 hours (schemas, configuration)
			'persistent_data'     => 86400, // 24 hours (rarely changing data)
		];
	}

	/**
	 * Get recommended backend priority order.
	 *
	 * @return array<string>
	 */
	private function getBackendPriorities(): array
	{
		return [
			'primary'   => 'redis',      // Fastest, most features
			'secondary' => 'memcached',  // Fast, simple
			'fallback'  => 'filesystem', // Reliable, persistent
			'system'    => 'opcache',    // PHP bytecode cache
		];
	}

	/**
	 * Get performance optimization tips.
	 *
	 * @return array<string>
	 */
	private function getPerformanceTips(): array
	{
		return [
			'Use appropriate TTL values for different data types',
			'Monitor cache hit rates to optimize cache sizing',
			'Consider Redis over Memcached for advanced features',
			'Enable OPcache for PHP bytecode acceleration',
			'Use filesystem cache as reliable fallback option',
			'Clear specific cache types instead of full cache clears',
		];
	}

	/**
	 * Determine if caching is currently enabled.
	 *
	 * Cache is considered enabled when:
	 * - Development mode is not active
	 * - At least one cache backend is available
	 */
	private function isCacheEnabled(): bool
	{
		// Check if development mode is active (overrides cache settings)
		$devModeManager = new \TotalCMS\Domain\Cache\Service\DevModeManager();
		if ($devModeManager->isDevModeActive()) {
			return false;
		}

		// Check if at least one cache backend is available
		return $this->filesystemService->isAvailable()
			   || $this->opcacheService->isAvailable()
			   || $this->redisService->isAvailable()
			   || $this->memcachedService->isAvailable();
	}

	/**
	 * Get available cache backends with their display names.
	 *
	 * @return array<string,string>
	 */
	private function getAvailableBackends(): array
	{
		return [
			'filesystem' => 'Filesystem Cache',
			'opcache'    => 'OPcache',
			'redis'      => 'Redis',
			'memcached'  => 'Memcached',
		];
	}

	/**
	 * Get 3-state status for each cache backend.
	 *
	 * @return array<string,string>
	 */
	private function getBackendStatus(): array
	{
		return [
			'filesystem' => $this->getServiceStatus($this->filesystemService),
			'opcache'    => $this->getServiceStatus($this->opcacheService),
			'redis'      => $this->getServiceStatus($this->redisService),
			'memcached'  => $this->getServiceStatus($this->memcachedService),
		];
	}

	/**
	 * Get status for a specific cache service.
	 *
	 * @param FilesystemService|OPcacheService|RedisService|MemcachedService $service
	 */
	private function getServiceStatus(mixed $service): string
	{
		if (!$service->isInstalled()) {
			return 'not_installed';
		}

		if ($service->isActive()) {
			return 'active';
		}

		return 'available';
	}
}

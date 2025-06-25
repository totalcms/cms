<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Support\Config;
use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Cache\Service\MemcachedService;

/**
 * Enhanced Twig cache management with modular cache services.
 */
final class TwigCacheManager
{
	private const NO_CACHE = 'dev-no-cache';

	/** @var string Current cache version for invalidation */
	private string $cacheVersion;
	private string $versionFile = '.cache_version';

	/** @var array<CacheInterface> Available cache services */
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
	 * Clear all Twig caches including OPcache.
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
	 * Optimize cache configuration based on available backends.
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

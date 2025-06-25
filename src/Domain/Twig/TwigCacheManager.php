<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Support\Config;

/**
 * Enhanced Twig cache management with OPcache, Redis, and Memcache support.
 */
final class TwigCacheManager
{
	// Cache keys for future external cache implementation
	// private const CACHE_VERSION_KEY = 'twig_cache_version';
	// private const TEMPLATE_METADATA_KEY = 'twig_template_metadata';
	
	/** @var string Current cache version for invalidation */
	private string $cacheVersion;
	
	/** @var array<string,string> Available cache backends */
	private array $availableBackends = [];

	public function __construct(
		private Config $config,
	) {
		$this->detectAvailableBackends();
		$this->cacheVersion = $this->getCacheVersion();
	}

	/**
	 * Detect available caching backends.
	 */
	private function detectAvailableBackends(): void
	{
		// OPcache detection
		if (function_exists('opcache_get_status') && opcache_get_status() !== false) {
			$this->availableBackends['opcache'] = 'OPcache';
		}
		
		// Redis detection
		if (extension_loaded('redis') && class_exists('Redis')) {
			try {
				$redis = new \Redis();
				$redis->connect('127.0.0.1', 6379, 1); // 1 second timeout
				$redis->ping();
				$redis->close();
				$this->availableBackends['redis'] = 'Redis';
			} catch (\Exception $e) {
				// Redis not available
			}
		}
		
		// Memcache detection
		if (extension_loaded('memcached') && class_exists('Memcached')) {
			try {
				$memcached = new \Memcached();
				$memcached->addServer('127.0.0.1', 11211);
				$memcached->set('test', 'test', 1);
				if ($memcached->get('test') === 'test') {
					$this->availableBackends['memcached'] = 'Memcached';
				}
			} catch (\Exception $e) {
				// Memcached not available
			}
		}
		
		// File system is always available
		$this->availableBackends['filesystem'] = 'File System';
	}

	/**
	 * Get the current cache version for invalidation.
	 */
	private function getCacheVersion(): string
	{
		// Don't create version files when caching is disabled
		if ($this->config->cachedir === 'false' || $this->config->cachedir === false) {
			return 'dev-no-cache';
		}
		
		$versionFile = $this->config->cachedir . '/.cache_version';
		
		if (file_exists($versionFile)) {
			return (string)file_get_contents($versionFile);
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
		// Don't create version files when caching is disabled
		if ($this->config->cachedir === 'false' || $this->config->cachedir === false) {
			$this->cacheVersion = $version;
			return;
		}
		
		$versionFile = $this->config->cachedir . '/.cache_version';
		
		if (!is_dir($this->config->cachedir)) {
			mkdir($this->config->cachedir, 0755, true);
		}
		
		file_put_contents($versionFile, $version);
		$this->cacheVersion = $version;
	}

	/**
	 * Clear all Twig caches including OPcache.
	 */
	public function clearAllCaches(): bool
	{
		// When caching is disabled, only clear OPcache if available
		if ($this->config->cachedir === 'false' || $this->config->cachedir === false) {
			$success = true;
			
			// Still clear OPcache in dev mode to ensure fresh PHP compilation
			if (isset($this->availableBackends['opcache'])) {
				$success = $success && $this->clearOPcache();
			}
			
			// Update cache version (but don't write to file)
			$this->setCacheVersion(date('Y-m-d-H-i-s') . '-' . uniqid());
			
			return $success;
		}
		
		// Clear file system cache
		$success = $this->clearFileSystemCache();
		
		// Clear OPcache
		if (isset($this->availableBackends['opcache'])) {
			$success = $success && $this->clearOPcache();
		}
		
		// Clear Redis cache
		if (isset($this->availableBackends['redis'])) {
			$success = $success && $this->clearRedisCache();
		}
		
		// Clear Memcached cache
		if (isset($this->availableBackends['memcached'])) {
			$success = $success && $this->clearMemcachedCache();
		}
		
		// Generate new cache version
		$this->setCacheVersion(date('Y-m-d-H-i-s') . '-' . uniqid());
		
		return $success;
	}

	/**
	 * Clear file system cache.
	 */
	private function clearFileSystemCache(): bool
	{
		$cacheDir = $this->config->cachedir;
		
		if (!file_exists($cacheDir)) {
			return true;
		}
		
		return $this->deleteDirectory($cacheDir, true);
	}

	/**
	 * Clear OPcache for Twig compiled templates.
	 */
	private function clearOPcache(): bool
	{
		if (!function_exists('opcache_reset')) {
			return false;
		}
		
		// Reset the entire OPcache
		return opcache_reset();
	}

	/**
	 * Clear Redis cache.
	 */
	private function clearRedisCache(): bool
	{
		try {
			$redis = new \Redis();
			$redis->connect('127.0.0.1', 6379, 1);
			
			// Clear Twig-specific keys
			$keys = $redis->keys('twig:*');
			if (!empty($keys)) {
				$redis->del($keys);
			}
			
			$redis->close();
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Clear Memcached cache.
	 */
	private function clearMemcachedCache(): bool
	{
		try {
			$memcached = new \Memcached();
			$memcached->addServer('127.0.0.1', 11211);
			
			// Flush all keys (be careful in shared environments)
			return $memcached->flush();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Get cache statistics and health information.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheStats(): array
	{
		$stats = [
			'available_backends' => $this->availableBackends,
			'cache_version' => $this->cacheVersion,
			'filesystem' => $this->getFileSystemStats(),
		];
		
		if (isset($this->availableBackends['opcache'])) {
			$stats['opcache'] = $this->getOPcacheStats();
		}
		
		if (isset($this->availableBackends['redis'])) {
			$stats['redis'] = $this->getRedisStats();
		}
		
		if (isset($this->availableBackends['memcached'])) {
			$stats['memcached'] = $this->getMemcachedStats();
		}
		
		return $stats;
	}

	/**
	 * Get file system cache statistics.
	 *
	 * @return array<string,mixed>
	 */
	private function getFileSystemStats(): array
	{
		$cacheDir = $this->config->cachedir;
		
		if (!is_dir($cacheDir)) {
			return ['exists' => false, 'size' => 0, 'files' => 0];
		}
		
		$size = 0;
		$files = 0;
		
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($cacheDir)
		);
		
		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$size += $file->getSize();
				$files++;
			}
		}
		
		return [
			'exists' => true,
			'size' => $size,
			'files' => $files,
			'size_mb' => round($size / 1024 / 1024, 2),
		];
	}

	/**
	 * Get OPcache statistics.
	 *
	 * @return array<string,mixed>
	 */
	private function getOPcacheStats(): array
	{
		if (!function_exists('opcache_get_status')) {
			return ['available' => false];
		}
		
		$status = opcache_get_status(false);
		
		if ($status === false) {
			return ['available' => false];
		}
		
		return [
			'available' => true,
			'enabled' => $status['opcache_enabled'] ?? false,
			'cache_full' => $status['cache_full'] ?? false,
			'memory_usage' => $status['memory_usage'] ?? [],
			'hit_rate' => isset($status['opcache_statistics']['opcache_hit_rate']) 
				? round($status['opcache_statistics']['opcache_hit_rate'], 2) 
				: 0,
		];
	}

	/**
	 * Get Redis statistics.
	 *
	 * @return array<string,mixed>
	 */
	private function getRedisStats(): array
	{
		try {
			$redis = new \Redis();
			$redis->connect('127.0.0.1', 6379, 1);
			
			$info = $redis->info();
			$redis->close();
			
			return [
				'available' => true,
				'memory_usage' => $info['used_memory_human'] ?? 'Unknown',
				'connected_clients' => $info['connected_clients'] ?? 0,
				'hit_rate' => isset($info['keyspace_hits'], $info['keyspace_misses'])
					? round(($info['keyspace_hits'] / ($info['keyspace_hits'] + $info['keyspace_misses'])) * 100, 2)
					: 0,
			];
		} catch (\Exception $e) {
			return ['available' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Get Memcached statistics.
	 *
	 * @return array<string,mixed>
	 */
	private function getMemcachedStats(): array
	{
		try {
			$memcached = new \Memcached();
			$memcached->addServer('127.0.0.1', 11211);
			
			$stats = $memcached->getStats();
			$serverStats = reset($stats);
			
			return [
				'available' => true,
				'memory_usage' => isset($serverStats['bytes']) 
					? round($serverStats['bytes'] / 1024 / 1024, 2) . 'MB'
					: 'Unknown',
				'hit_rate' => isset($serverStats['get_hits'], $serverStats['get_misses'])
					? round(($serverStats['get_hits'] / ($serverStats['get_hits'] + $serverStats['get_misses'])) * 100, 2)
					: 0,
			];
		} catch (\Exception $e) {
			return ['available' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Optimize cache configuration based on available backends.
	 *
	 * @return array<string,mixed>
	 */
	public function getOptimalCacheConfig(): array
	{
		$config = [
			'cache_dir' => $this->config->cachedir,
			'recommendations' => [],
		];
		
		// OPcache recommendations
		if (isset($this->availableBackends['opcache'])) {
			$opcacheStatus = $this->getOPcacheStats();
			
			if ($opcacheStatus['available'] && $opcacheStatus['enabled']) {
				$config['recommendations'][] = '✅ OPcache is enabled and will accelerate compiled Twig templates';
				
				if ($opcacheStatus['hit_rate'] < 90) {
					$config['recommendations'][] = '⚠️ OPcache hit rate is low (' . $opcacheStatus['hit_rate'] . '%), consider increasing memory';
				}
			} else {
				$config['recommendations'][] = '❌ OPcache is available but not enabled - enable for better performance';
			}
		} else {
			$config['recommendations'][] = '❌ OPcache not available - consider enabling for 2-5x performance improvement';
		}
		
		// Redis recommendations
		if (isset($this->availableBackends['redis'])) {
			$config['recommendations'][] = '✅ Redis is available for template metadata caching';
		}
		
		// Memcached recommendations  
		if (isset($this->availableBackends['memcached'])) {
			$config['recommendations'][] = '✅ Memcached is available for template metadata caching';
		}
		
		return $config;
	}

	/**
	 * Recursively delete directory contents.
	 */
	private function deleteDirectory(string $dir, bool $preserveRoot = false): bool
	{
		if (!file_exists($dir)) {
			return true;
		}

		if (!is_dir($dir)) {
			return unlink($dir);
		}

		$items = scandir($dir);
		if ($items === false) {
			return false;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (!$this->deleteDirectory($path)) {
				return false;
			}
		}

		return $preserveRoot ? true : rmdir($dir);
	}
}
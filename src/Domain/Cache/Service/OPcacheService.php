<?php

namespace TotalCMS\Domain\Cache\Service;

/**
 * OPcache service for bytecode caching.
 * OPcache is controlled by PHP configuration (php.ini), not application config.
 */
class OPcacheService implements CacheInterface
{
	// No configuration needed - OPcache runs automatically when enabled in PHP

	public function isAvailable(): bool
	{
		// Check if OPcache is actually enabled and working in PHP
		return function_exists('opcache_get_status') && opcache_get_status() !== false;
	}

	public function isInstalled(): bool
	{
		return function_exists('opcache_get_status');
	}

	public function isActive(): bool
	{
		return $this->isAvailable();
	}

	public function get(string $key): mixed
	{
		// OPcache doesn't support key-value storage
		return null;
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		// OPcache doesn't support key-value storage
		return false;
	}

	public function delete(string $key): bool
	{
		// OPcache can invalidate specific files
		if (file_exists($key)) {
			return opcache_invalidate($key, true);
		}

		return false;
	}

	public function clear(): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		return opcache_reset();
	}

	public function getStats(): array
	{
		if (!$this->isAvailable()) {
			return ['available' => false];
		}

		$status = opcache_get_status(false);
		if ($status === false) {
			return ['available' => false];
		}

		return [
			'available'       => true,
			'opcache_enabled' => $status['opcache_enabled'] ?? false,
			'cache_full'      => $status['cache_full'] ?? false,
			'memory_usage'    => $status['memory_usage'] ?? [],
			'hit_rate'        => isset($status['opcache_statistics']['opcache_hit_rate'])
				? round($status['opcache_statistics']['opcache_hit_rate'], 1)
				: 0,
			'scripts_cached' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
		];
	}

	public function getName(): string
	{
		return 'OPcache';
	}

	public function getRecommendations(): array
	{
		if (!$this->isAvailable()) {
			return ['❌ OPcache not available - consider enabling for 2-5x performance improvement'];
		}

		$stats           = $this->getStats();
		$recommendations = [];

		if (($stats['available'] ?? false) && ($stats['opcache_enabled'] ?? false)) {
			$recommendations[] = '✅ OPcache is enabled and will accelerate compiled Twig templates';

			$hitRate = $stats['hit_rate'] ?? 0;
			if ($hitRate < 90) {
				$recommendations[] = '⚠️ OPcache hit rate is low (' . $hitRate . '%), consider increasing memory';
			}
		} else {
			$recommendations[] = '❌ OPcache is available but not enabled - enable for better performance';
		}

		return $recommendations;
	}
}

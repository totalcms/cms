<?php

namespace TotalCMS\Domain\Cache\Service;

use Memcached;
use TotalCMS\Support\Config;

/**
 * Memcached cache service.
 */
class MemcachedService implements CacheInterface
{
	private readonly bool $enabled;
	private readonly string $host;
	private readonly int $port;
	private ?\Memcached $memcached = null;

	public function __construct(
		Config $config,
	) {
		$this->enabled   = $config->cache['memcached'] ?? true;
		$memcachedConfig = $config->cache['memcachedConfig'] ?? [];
		$this->host      = $memcachedConfig['host'] ?? '127.0.0.1';
		$this->port      = $memcachedConfig['port'] ?? 11211;
	}

	public function isAvailable(): bool
	{
		if (!$this->enabled || !extension_loaded('memcached') || !class_exists('Memcached')) {
			return false;
		}

		try {
			$memcached = $this->getConnection();
			$memcached->set('test_key', 'test_value', 1);

			return $memcached->get('test_key') === 'test_value';
		} catch (\Exception) {
			return false;
		}
	}

	public function isInstalled(): bool
	{
		return extension_loaded('memcached') && class_exists('Memcached');
	}

	public function isActive(): bool
	{
		return $this->enabled && $this->isAvailable();
	}

	public function get(string $key): mixed
	{
		if (!$this->isAvailable()) {
			return null;
		}

		try {
			$memcached = $this->getConnection();
			$value     = $memcached->get($key);

			return $value !== false ? $value : null;
		} catch (\Exception) {
			return null;
		}
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			$memcached = $this->getConnection();

			return $memcached->set($key, $value, $ttl);
		} catch (\Exception) {
			return false;
		}
	}

	public function delete(string $key): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			$memcached = $this->getConnection();

			return $memcached->delete($key);
		} catch (\Exception) {
			return false;
		}
	}

	public function clear(): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			$memcached = $this->getConnection();

			return $memcached->flush();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Clear cache entries by pattern.
	 * Note: Memcached doesn't support native pattern matching like Redis,
	 * so this implementation falls back to clearing all cache.
	 * A more sophisticated implementation would require tracking keys separately.
	 */
	public function clearByPattern(string $pattern): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		// Memcached doesn't have native pattern support like Redis SCAN
		// For now, fall back to clearing all cache to ensure patterns are cleared
		// TODO: Consider implementing key tracking for more precise pattern clearing
		try {
			$memcached = $this->getConnection();

			return $memcached->flush();
		} catch (\Exception) {
			return false;
		}
	}

	public function getStats(): array
	{
		if (!$this->isAvailable()) {
			return [
				'available' => false,
				'enabled'   => $this->enabled,
				'host'      => $this->host,
				'port'      => $this->port,
			];
		}

		try {
			$memcached   = $this->getConnection();
			$stats       = $memcached->getStats();
			$serverStats = reset($stats);

			$hits   = (int)($serverStats['get_hits'] ?? 0);
			$misses = (int)($serverStats['get_misses'] ?? 0);
			$total  = $hits + $misses;

			return [
				'available'    => true,
				'enabled'      => $this->enabled,
				'host'         => $this->host,
				'port'         => $this->port,
				'memory_usage' => isset($serverStats['bytes'])
					? round($serverStats['bytes'] / 1024 / 1024, 2) . 'MB'
					: 'Unknown',
				'hit_rate' => $total > 0 ? round(($hits / $total) * 100, 1) : 0,
				'items'    => $serverStats['curr_items'] ?? 0,
			];
		} catch (\Exception $e) {
			return [
				'available' => false,
				'enabled'   => $this->enabled,
				'error'     => $e->getMessage(),
			];
		}
	}

	public function getName(): string
	{
		return 'Memcached';
	}

	public function getRecommendations(): array
	{
		if (!$this->isAvailable()) {
			return ['⚠️ Memcached not available - can be used for distributed caching'];
		}

		return ['✅ Memcached is available for template metadata caching'];
	}

	private function getConnection(): \Memcached
	{
		if (!$this->memcached instanceof \Memcached) {
			$this->memcached = new \Memcached();
			$this->memcached->addServer($this->host, $this->port);
		}

		return $this->memcached;
	}
}

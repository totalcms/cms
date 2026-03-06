<?php

namespace TotalCMS\Domain\Cache\Service;

use Redis;
use TotalCMS\Support\Config;

/**
 * Redis cache service.
 */
class RedisService implements CacheInterface
{
	private readonly bool $enabled;
	private readonly string $host;
	private readonly int $port;
	private readonly int $timeout;
	private readonly ?string $password;
	private readonly int $database;
	private ?\Redis $redis = null;

	public function __construct(
		Config $config,
	) {
		$this->enabled  = $config->cache['redis'] ?? true;
		$redisConfig    = $config->cache['redisConfig'] ?? [];
		$this->host     = $redisConfig['host'] ?? '127.0.0.1';
		$this->port     = $redisConfig['port'] ?? 6379;
		$this->timeout  = $redisConfig['timeout'] ?? 1;
		$this->password = $redisConfig['password'] ?? null;
		$this->database = $redisConfig['database'] ?? 0;
	}

	public function isAvailable(): bool
	{
		if (!$this->enabled || !extension_loaded('redis') || !class_exists('Redis')) {
			return false;
		}

		try {
			$redis = $this->getConnection();
			$redis->ping();

			return true;
		} catch (\Exception) {
			return false;
		}
	}

	public function isInstalled(): bool
	{
		return extension_loaded('redis') && class_exists('Redis');
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
			$redis = $this->getConnection();
			$value = $redis->get($key);

			return $value !== false ? unserialize($value, ['allowed_classes' => false]) : null;
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
			$redis      = $this->getConnection();
			$serialized = serialize($value);

			if ($ttl > 0) {
				return $redis->setex($key, $ttl, $serialized);
			}

			return $redis->set($key, $serialized);
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
			$redis  = $this->getConnection();
			$result = $redis->del($key);

			return is_int($result) && $result > 0;
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
			$redis = $this->getConnection();

			return $redis->flushDB();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Clear cache entries by pattern using Redis SCAN and DEL.
	 */
	public function clearByPattern(string $pattern): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			$redis    = $this->getConnection();
			$iterator = null;
			$deleted  = 0;

			// Use SCAN to find keys matching the pattern
			while (($keys = $redis->scan($iterator, $pattern)) !== false) {
				if ($keys !== []) {
					$deleted += $redis->del($keys);
				}
				if ($iterator === 0) {
					break; // SCAN completed
				}
			}

			return true;
		} catch (\Exception) {
			// Fall back to clearing everything if pattern clearing fails
			return $this->clear();
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
			$redis = $this->getConnection();
			$info  = $redis->info();

			$hits   = (int)($info['keyspace_hits'] ?? 0);
			$misses = (int)($info['keyspace_misses'] ?? 0);
			$total  = $hits + $misses;

			return [
				'available'         => true,
				'enabled'           => $this->enabled,
				'host'              => $this->host,
				'port'              => $this->port,
				'memory_usage'      => $info['used_memory_human'] ?? 'Unknown',
				'connected_clients' => $info['connected_clients'] ?? 0,
				'hit_rate'          => $total > 0 ? round(($hits / $total) * 100, 1) : 0,
				'keys'              => $redis->dbSize(),
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
		return 'Redis';
	}

	public function getRecommendations(): array
	{
		if (!$this->isAvailable()) {
			return ['⚠️ Redis not available - can be used for distributed caching'];
		}

		return ['✅ Redis is available for template metadata caching'];
	}

	private function getConnection(): \Redis
	{
		if (!$this->redis instanceof \Redis) {
			$this->redis = new \Redis();
			$this->redis->connect($this->host, $this->port, $this->timeout);

			if ($this->password !== null) {
				$this->redis->auth($this->password);
			}

			if ($this->database > 0) {
				$this->redis->select($this->database);
			}
		}

		return $this->redis;
	}
}

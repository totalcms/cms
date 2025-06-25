<?php

namespace TotalCMS\Domain\Cache\Service;

/**
 * Common interface for all cache backends.
 */
interface CacheInterface
{
	/**
	 * Check if the cache backend is available and configured.
	 */
	public function isAvailable(): bool;

	/**
	 * Get a value from the cache.
	 *
	 * @param string $key The cache key
	 *
	 * @return mixed The cached value or null if not found
	 */
	public function get(string $key): mixed;

	/**
	 * Set a value in the cache.
	 *
	 * @param string $key The cache key
	 * @param mixed $value The value to cache
	 * @param int $ttl Time to live in seconds (0 = forever)
	 *
	 * @return bool True on success
	 */
	public function set(string $key, mixed $value, int $ttl = 0): bool;

	/**
	 * Delete a value from the cache.
	 *
	 * @param string $key The cache key
	 *
	 * @return bool True on success
	 */
	public function delete(string $key): bool;

	/**
	 * Clear all values from the cache.
	 *
	 * @return bool True on success
	 */
	public function clear(): bool;

	/**
	 * Get cache statistics.
	 *
	 * @return array<string,mixed>
	 */
	public function getStats(): array;

	/**
	 * Get the cache backend name.
	 */
	public function getName(): string;

	/**
	 * Get recommendations for this cache service.
	 *
	 * @return array<string>
	 */
	public function getRecommendations(): array;
}

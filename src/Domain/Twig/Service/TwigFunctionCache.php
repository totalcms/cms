<?php

namespace TotalCMS\Domain\Twig\Service;

/**
 * Simple in-memory cache for expensive Twig function results.
 * Caches results for the duration of a single request.
 */
class TwigFunctionCache
{
	/** @var array<string,mixed> */
	private static array $cache = [];

	/**
     * Get a cached result or execute the function and cache it.
     *
     * @param array<mixed> $args
     */
    public static function remember(string $key, callable $function, array $args = []): mixed
	{
		$cacheKey = self::generateCacheKey($key, $args);

		if (!isset(self::$cache[$cacheKey])) {
			self::$cache[$cacheKey] = $function(...$args);
		}

		return self::$cache[$cacheKey];
	}

	/**
	 * Check if a result is cached.
	 *
	 * @param array<mixed> $args
	 */
	public static function has(string $key, array $args = []): bool
	{
		$cacheKey = self::generateCacheKey($key, $args);

		return isset(self::$cache[$cacheKey]);
	}

	/**
	 * Clear all cached results.
	 */
	public static function clear(): void
	{
		self::$cache = [];
	}

	/**
	 * Clear cached results for a specific function.
	 */
	public static function forget(string $key): void
	{
		$pattern = $key . ':';
		foreach (array_keys(self::$cache) as $cacheKey) {
			if (str_starts_with($cacheKey, $pattern)) {
				unset(self::$cache[$cacheKey]);
			}
		}
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array<string,int>
	 */
	public static function getStats(): array
	{
		return [
			'count'  => count(self::$cache),
			'memory' => strlen(serialize(self::$cache)),
		];
	}

	/**
	 * Generate a cache key from function name and arguments.
	 *
	 * @param array<mixed> $args
	 */
	private static function generateCacheKey(string $key, array $args): string
	{
		if ($args === []) {
			return $key;
		}

		// Use a short hash for efficiency
		return $key . ':' . md5(serialize($args));
	}
}

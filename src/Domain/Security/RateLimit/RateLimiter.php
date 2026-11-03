<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\RateLimit;

use TotalCMS\Domain\Cache\CacheManager;

/**
 * The counter behind every rate limit: at most `$max` requests per key in a
 * window of `$window` seconds.
 *
 * A window opens at a key's first request and closes `$window` seconds later;
 * requests inside it never extend it. Blocked requests are not counted, so a
 * caller who backs off gets the full allowance when the window closes.
 *
 * Counters go through `CacheManager`, so Redis gives accurate counts across
 * workers; on APCu alone each worker counts separately and the effective
 * limit is `$max × workers`. Fail-open: when every cache backend is down the
 * counter reads empty and requests pass, rather than locking out legitimate
 * traffic on a broken cache.
 */
class RateLimiter
{
	private const CACHE_PREFIX = 'rl_';

	/** @var \Closure(): int */
	private \Closure $clock;

	/**
	 * @param (\Closure(): int)|null $clock current Unix time, for tests
	 */
	public function __construct(
		private readonly CacheManager $cache,
		?\Closure $clock = null,
	) {
		$this->clock = $clock ?? time(...);
	}

	/**
	 * Count one request by `$subject` (an IP, a template id, '' for a
	 * site-wide limit) against the limit called `$name`. The cache key is
	 * `rl_{name}_{md5(subject)}`, so `clearByPatternAllBackends("rl_{name}_*")`
	 * resets one limit.
	 */
	public function attempt(string $name, string $subject, int $max, int $window): RateLimitResult
	{
		$window = max(1, $window);

		if ($max <= 0) {
			return new RateLimitResult(true, 0, 0, $window, 0);
		}

		$now      = ($this->clock)();
		$cacheKey = self::CACHE_PREFIX . $name . '_' . md5($subject);
		[$count, $start] = $this->read($cacheKey, $now, $window);
		$retryAfter = max(1, $start + $window - $now);

		if ($count >= $max) {
			return new RateLimitResult(false, $max, 0, $window, $retryAfter);
		}

		$count++;
		$this->cache->storeData($cacheKey, ['count' => $count, 'start' => $start], $retryAfter);

		return new RateLimitResult(true, $max, $max - $count, $window, $retryAfter);
	}

	/**
	 * The count and start of the window open at `$now`, or a fresh window when
	 * none is stored, the stored one has closed, or the entry is unreadable.
	 *
	 * @return array{int, int}
	 */
	private function read(string $cacheKey, int $now, int $window): array
	{
		// Counters are not content: they must keep counting in dev mode and
		// with the cache disabled, where getData() reads nothing.
		$entry = $this->cache->getOperationalData($cacheKey);

		if (
			!is_array($entry)
			|| !is_int($entry['count'] ?? null)
			|| !is_int($entry['start'] ?? null)
			|| $now >= $entry['start'] + $window
		) {
			return [0, $now];
		}

		return [$entry['count'], $entry['start']];
	}
}

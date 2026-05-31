<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;

/**
 * Central safety brain for extension hook calls.
 *
 * Wraps each extension hook in try/catch so a thrown extension can never crash a
 * request: on a throw the failure is logged with extension + hook attribution and
 * the caller's fallback is returned. Failures are counted in a rolling cache
 * window (de-duplicated to one per extension per request). When the count reaches
 * the threshold on a true production request the extension is auto-quarantined.
 */
final class ExtensionGuard
{
	/** @var array<string,true> Extensions already counted this request. */
	private array $countedThisRequest = [];

	public function __construct(
		private readonly EnvironmentResolver $env,
		private readonly CacheManager $cache,
		private readonly ExtensionStateRepository $stateRepository,
		private readonly LoggerInterface $logger,
		private readonly ExtensionProfiler $profiler,
		private readonly int $threshold = 5,
		private readonly int $windowSeconds = 300,
	) {
	}

	/**
	 * Execute a hook callable under the guard.
	 *
	 * @template T
	 *
	 * @param callable():T $callable
	 * @param T $fallback
	 *
	 * @return T
	 */
	public function run(string $extensionId, string $hookType, callable $callable, mixed $fallback): mixed
	{
		try {
			return $this->profiler->time($extensionId, $callable);
		} catch (\Throwable $e) {
			$this->recordFailure($extensionId, $hookType, $e);

			return $fallback;
		}
	}

	/**
	 * Reset the rolling failure counter for an extension.
	 *
	 * Called when an operator re-enables a quarantined extension so it starts
	 * with a clean slate instead of inheriting a count that may already be at
	 * (or near) the threshold — otherwise a single fresh crash would re-trip
	 * quarantine immediately. Writes 0 with the window TTL rather than deleting
	 * the key: CacheManager's single-key delete (clearData) touches every cache
	 * backend AND clears OPcache, which is far too heavy for a counter reset, so
	 * a plain overwrite is the right tool here.
	 */
	public function resetFailures(string $extensionId): void
	{
		$this->cache->storeData($this->cacheKey($extensionId), 0, $this->windowSeconds);
	}

	private function recordFailure(string $extensionId, string $hookType, \Throwable $e): void
	{
		$this->logger->error("Extension '{$extensionId}' crashed in {$hookType}: {$e->getMessage()}", [
			'extension' => $extensionId,
			'hook'      => $hookType,
			'exception' => $e,
		]);

		// De-dup: count each extension at most once per request so a hook that
		// fires many times (e.g. a Twig function in a loop) only adds one failure.
		if (isset($this->countedThisRequest[$extensionId])) {
			return;
		}
		$this->countedThisRequest[$extensionId] = true;

		$count = $this->incrementFailureCount($extensionId);

		if ($this->env->isQuarantineAllowed() && $count >= $this->threshold) {
			$this->quarantine($extensionId, $count, $e);
		}
	}

	/**
	 * Increment the rolling failure counter and return the new value.
	 *
	 * CacheManager has no atomic increment, so this is a best-effort
	 * get-current → +1 → store-with-TTL. A tiny race under heavy concurrency may
	 * undercount, which is acceptable for a crash counter — the threshold is just
	 * a trip-wire, not an exact ledger. The cache key TTL equals the window so the
	 * count naturally decays once the extension stops crashing.
	 */
	private function incrementFailureCount(string $extensionId): int
	{
		$key     = $this->cacheKey($extensionId);
		$current = $this->cache->getData($key);
		$count   = (is_int($current) ? $current : 0) + 1;

		$this->cache->storeData($key, $count, $this->windowSeconds);

		return $count;
	}

	private function quarantine(string $extensionId, int $count, \Throwable $e): void
	{
		$state = $this->stateRepository->getState($extensionId);
		if ($state === null || $state->isQuarantined()) {
			return;
		}

		$state->quarantine = [
			'reason'        => 'Crashed repeatedly',
			'failureCount'  => $count,
			'lastError'     => $e->getMessage(),
			'quarantinedAt' => gmdate('c'),
		];
		$this->stateRepository->saveState($extensionId, $state);

		$this->logger->warning("Extension '{$extensionId}' auto-quarantined after {$count} failures.");
	}

	/** Current rolling failure count for an extension (0 when none recorded). */
	public function failureCountFor(string $extensionId): int
	{
		// Operational read: bypass the devmode/cacheDisabled read-skip so the
		// recent-errors count stays visible in the admin while devmode is active.
		$count = $this->cache->getOperationalData($this->cacheKey($extensionId));

		return is_int($count) ? $count : 0;
	}

	private function cacheKey(string $extensionId): string
	{
		return "extguard:fail:{$extensionId}";
	}
}

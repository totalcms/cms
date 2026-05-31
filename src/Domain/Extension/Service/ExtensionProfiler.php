<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;

/**
 * Per-request timing for extension hooks. Cheap and sampled.
 *
 * dev/preview: always profiles. prod: profiles 1-in-N requests (sampleRate),
 * or never when sampleRate is 0. On a non-profiled request time() is a
 * near-zero pass-through.
 */
final class ExtensionProfiler
{
	private readonly bool $profiling;

	/** @var array<string,int> extensionId => accumulated microseconds */
	private array $totals = [];

	public function __construct(
		EnvironmentResolver $env,
		private readonly CacheManager $cache,
		int $sampleRate,
		private readonly LoggerInterface $logger,
	) {
		$this->profiling = $this->decide($env, $sampleRate);
	}

	private function decide(EnvironmentResolver $env, int $sampleRate): bool
	{
		if ($env->shouldSurfaceErrors()) {
			return true;
		}
		if ($sampleRate <= 0) {
			return false;
		}

		return random_int(1, $sampleRate) === 1;
	}

	public function isProfiling(): bool
	{
		return $this->profiling;
	}

	/**
	 * @template T
	 *
	 * @param callable():T $callable
	 *
	 * @return T
	 */
	public function time(string $extensionId, callable $callable): mixed
	{
		if (!$this->profiling) {
			return $callable();
		}
		$start                      = hrtime(true);
		$result                     = $callable();
		$this->totals[$extensionId] = ($this->totals[$extensionId] ?? 0) + (int)((hrtime(true) - $start) / 1000);

		return $result;
	}

	public function record(string $extensionId, int $micros): void
	{
		if ($this->profiling) {
			$this->totals[$extensionId] = ($this->totals[$extensionId] ?? 0) + $micros;
		}
	}

	public function totalMicrosFor(string $extensionId): int
	{
		return $this->totals[$extensionId] ?? 0;
	}

	/**
	 * Read the rolling per-extension health metrics from cache (written by flush()).
	 *
	 * @return array{lastMs:float,avgMs:float,samples:int}|null
	 */
	public function metricsFor(string $extensionId): ?array
	{
		// Operational read: bypass the devmode/cacheDisabled read-skip so health
		// metrics stay visible in the admin while devmode is active.
		$data = $this->cache->getOperationalData("extprof:{$extensionId}");
		if (!is_array($data)) {
			return null;
		}

		return [
			'lastMs'  => (float)($data['lastMs'] ?? 0),
			'avgMs'   => (float)($data['avgMs'] ?? 0),
			'samples' => (int)($data['samples'] ?? 0),
		];
	}

	/** Flush rolling per-extension metrics to the cache. Call once per request. */
	public function flush(): void
	{
		if (!$this->profiling || $this->totals === []) {
			return;
		}
		foreach ($this->totals as $extensionId => $micros) {
			$key  = "extprof:{$extensionId}";
			$prev = $this->cache->getData($key);
			$ms   = $micros / 1000;

			if (is_array($prev)) {
				$samples = (int)$prev['samples'] + 1;
				$avg     = ((float)$prev['avgMs'] * (int)$prev['samples'] + $ms) / $samples;
			} else {
				$samples = 1;
				$avg     = $ms;
			}

			$this->cache->storeData($key, [
				'lastMs'  => round($ms, 1),
				'avgMs'   => round($avg, 1),
				'samples' => $samples,
			], 86400);
		}

		$this->logger->debug('Extension profiler flushed', ['extensions' => array_keys($this->totals)]);
	}
}

<?php

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Support\Config;

/**
 * APCu cache service.
 * APCu (APCu User Cache) is a fast, in-memory cache for single-server applications.
 * It provides excellent performance without requiring external services like Redis or Memcached.
 */
class APCuService implements CacheInterface
{
	private bool $enabled;

	/**
	 * Cached availability result to avoid repeated functional tests.
	 */
	private ?bool $availabilityCache = null;

	public function __construct(
		Config $config,
	) {
		$this->enabled = $config->cache['apcu'] ?? true;
	}

	public function isAvailable(): bool
	{
		// Return cached result if already tested
		if ($this->availabilityCache !== null) {
			return $this->availabilityCache;
		}

		if (!$this->enabled || !$this->isInstalled()) {
			$this->availabilityCache = false;
			return false;
		}

		// Test APCu functionality (only once per request)
		try {
			$testKey   = 'tcms_apcu_test';
			$testValue = 'test_value';

			// Test store and retrieve
			if (!apcu_store($testKey, $testValue, 1)) {
				$this->availabilityCache = false;
				return false;
			}

			$retrieved = apcu_fetch($testKey);
			apcu_delete($testKey);

			$this->availabilityCache = ($retrieved === $testValue);
			return $this->availabilityCache;
		} catch (\Exception) {
			$this->availabilityCache = false;
			return false;
		}
	}

	public function isInstalled(): bool
	{
		return extension_loaded('apcu') && function_exists('apcu_store') && function_exists('apcu_fetch');
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
			$value = apcu_fetch($key, $success);

			return $success ? $value : null;
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
			// APCu TTL of 0 means never expire, but we want reasonable defaults
			$actualTtl = $ttl === 0 ? 86400 : $ttl; // Default to 24 hours if no TTL specified

			return apcu_store($key, $value, $actualTtl);
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
			return apcu_delete($key);
		} catch (\Exception) {
			return false;
		}
	}

	public function clear(): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		// Clear all APCu entries (no prefix needed since domain hash is in keys)
		return apcu_clear_cache();
	}

	/**
	 * Clear cache entries by pattern (for APCu we use prefix matching).
	 */
	public function clearByPattern(string $pattern): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			// Convert shell-style pattern to regex (e.g., "abc123:collection:*" -> "/^abc123:collection:.*$/")
			$regexPattern = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '/';

			$iterator = new \APCUIterator($regexPattern);
			$keys     = [];

			foreach ($iterator as $entry) {
				$keys[] = $entry['key'];
			}

			if ($keys === []) {
				return true; // Nothing to clear
			}

			// apcu_delete with array returns array of failed keys, empty array means success
			$result = apcu_delete($keys);

			return empty($result);
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getStats(): array
	{
		if (!$this->isAvailable()) {
			return [
				'available' => false,
				'message'   => 'APCu not available',
			];
		}

		try {
			$info    = apcu_cache_info(true); // Get cache info without entries
			$smaInfo = apcu_sma_info();

			return [
				'available'    => true,
				'version'      => phpversion('apcu'),
				'memory_total' => $smaInfo['seg_size'] ?? 0,
				'memory_used'  => $smaInfo['seg_size'] - $smaInfo['avail_mem'],
				'memory_free'  => $smaInfo['avail_mem'] ?? 0,
				'entries'      => $info['num_entries'] ?? 0,
				'hits'         => $info['num_hits'] ?? 0,
				'misses'       => $info['num_misses'] ?? 0,
				'hit_rate'     => $this->calculateHitRate($info['num_hits'] ?? 0, $info['num_misses'] ?? 0),
				'uptime'       => $info['start_time'] ? time() - $info['start_time'] : 0,
			];
		} catch (\Exception) {
			return [
				'available' => true,
				'error'     => 'Could not retrieve APCu statistics',
			];
		}
	}

	public function getName(): string
	{
		return 'APCu';
	}

	/**
	 * @return array<string>
	 */
	public function getRecommendations(): array
	{
		$recommendations = [];

		if (!$this->isInstalled()) {
			$recommendations[] = 'Install APCu extension: apt-get install php-apcu (Ubuntu/Debian) or yum install php-apcu (CentOS/RHEL)';

			return $recommendations;
		}

		if (!$this->isAvailable()) {
			$recommendations[] = 'APCu is installed but not working. Check php.ini configuration';
			$recommendations[] = 'Ensure apc.enabled=1 in php.ini';
			$recommendations[] = 'For CLI usage, ensure apc.enable_cli=1 in php.ini';

			return $recommendations;
		}

		try {
			$smaInfo           = apcu_sma_info();
			$memoryUsedPercent = (($smaInfo['seg_size'] - $smaInfo['avail_mem']) / $smaInfo['seg_size']) * 100;

			if ($memoryUsedPercent > 90) {
				$recommendations[] = 'APCu memory usage is very high (' . round($memoryUsedPercent, 1) . '%). Consider increasing apc.shm_size in php.ini';
			} elseif ($memoryUsedPercent > 75) {
				$recommendations[] = 'APCu memory usage is getting high (' . round($memoryUsedPercent, 1) . '%). Monitor usage and consider increasing apc.shm_size if needed';
			}

			$stats = $this->getStats();
			if (isset($stats['hit_rate']) && $stats['hit_rate'] < 80) {
				$recommendations[] = 'APCu hit rate is low (' . round($stats['hit_rate'], 1) . '%). Consider adjusting TTL values or increasing memory';
			}

			if ($recommendations === []) {
				$recommendations[] = 'APCu is working well! Memory usage: ' . round($memoryUsedPercent, 1) . '%, Hit rate: ' . round($stats['hit_rate'] ?? 0, 1) . '%';
			}
		} catch (\Exception) {
			$recommendations[] = 'APCu is available but statistics could not be retrieved';
		}

		return $recommendations;
	}

	/**
	 * Calculate cache hit rate percentage.
	 */
	private function calculateHitRate(int $hits, int $misses): float
	{
		$total = $hits + $misses;

		return $total > 0 ? round(($hits / $total) * 100, 1) : 0;
	}
}

<?php

namespace TotalCMS\Domain\Cache;

use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Support\Config;

/**
 * Cache sizing advisor that analyzes CMS data sizes and provides
 * data-driven memory allocation recommendations for cache backends.
 */
readonly class CacheSizingAdvisor
{
	private const CACHE_KEY = 'cache_sizing_analysis';
	private const CACHE_TTL = 3600; // 1 hour

	/** Serialization overhead multiplier (PHP serialize adds ~50% overhead) */
	private const SERIALIZATION_OVERHEAD = 1.5;

	/** Per-entry overhead in bytes for each backend */
	private const APCU_ENTRY_OVERHEAD      = 400;
	private const REDIS_ENTRY_OVERHEAD     = 100;
	private const MEMCACHED_ENTRY_OVERHEAD = 100;

	/** Minimum allocation recommendation */
	private const MIN_ALLOCATION = 33554432; // 32MB

	public function __construct(
		private Config $config,
		private CollectionLister $collectionLister,
		private CacheManager $cacheManager,
		private APCuService $apcuService,
		private RedisService $redisService,
		private MemcachedService $memcachedService,
	) {
	}

	/**
	 * Get sizing analysis with caching support.
	 *
	 * @return array<string,mixed>
	 */
	public function getSizingAnalysis(bool $forceRefresh = false): array
	{
		if (!$forceRefresh) {
			$cached = $this->cacheManager->getComputedData(self::CACHE_KEY);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$scanResults     = $this->scanDataSizes();
		$recommendations = $this->calculateRecommendations($scanResults);
		$configExamples  = $this->generateConfigExamples($recommendations);

		$analysis = [
			'scan'            => $scanResults,
			'recommendations' => $recommendations,
			'config_examples' => $configExamples,
			'generated_at'    => date('Y-m-d H:i:s'),
		];

		$this->cacheManager->storeComputedData(self::CACHE_KEY, $analysis, self::CACHE_TTL);

		return $analysis;
	}

	/**
	 * Clear cached analysis results.
	 */
	public function clearCachedAnalysis(): bool
	{
		return $this->cacheManager->clearComputedData(self::CACHE_KEY);
	}

	/**
	 * Scan all collection data directories using filesize() stat calls only.
	 *
	 * @return array<string,mixed>
	 */
	private function scanDataSizes(): array
	{
		$collections       = $this->collectionLister->listAllCollections();
		$datadir           = $this->config->datadir;
		$perCollection     = [];
		$totalObjectCount  = 0;
		$totalObjectBytes  = 0;
		$totalIndexBytes   = 0;
		$totalMetaBytes    = 0;
		$collectionCount   = 0;

		foreach ($collections as $collection) {
			$collectionPath = $datadir . '/' . $collection->id;

			if (!is_dir($collectionPath)) {
				continue;
			}

			$collectionCount++;
			$info = $this->scanCollectionDirectory($collectionPath, $collection);

			$totalObjectCount += $info['object_count'];
			$totalObjectBytes += $info['object_bytes'];
			$totalIndexBytes += $info['index_bytes'];
			$totalMetaBytes += $info['meta_bytes'];

			$perCollection[] = $info;
		}

		// Sort by total size descending
		usort($perCollection, fn (array $a, array $b): int => ($b['object_bytes'] + $b['index_bytes'] + $b['meta_bytes']) <=> ($a['object_bytes'] + $a['index_bytes'] + $a['meta_bytes']));

		$totalBytes = $totalObjectBytes + $totalIndexBytes + $totalMetaBytes;

		return [
			'total_objects'       => $totalObjectCount,
			'total_objects_human' => number_format($totalObjectCount),
			'total_bytes'         => $totalBytes,
			'total_bytes_human'   => $this->formatBytes($totalBytes),
			'object_bytes'        => $totalObjectBytes,
			'object_bytes_human'  => $this->formatBytes($totalObjectBytes),
			'index_bytes'         => $totalIndexBytes,
			'index_bytes_human'   => $this->formatBytes($totalIndexBytes),
			'meta_bytes'          => $totalMetaBytes,
			'meta_bytes_human'    => $this->formatBytes($totalMetaBytes),
			'collection_count'    => $collectionCount,
			'collections'         => $perCollection,
		];
	}

	/**
	 * Scan a single collection directory.
	 *
	 * @return array<string,mixed>
	 */
	private function scanCollectionDirectory(string $collectionPath, CollectionData $collection): array
	{
		$indexBytes   = 0;
		$metaBytes    = 0;
		$objectCount  = 0;
		$objectBytes  = 0;
		$largestBytes = 0;

		// Measure index file
		$indexFile = $collectionPath . '/.index.json';
		if (file_exists($indexFile)) {
			$indexBytes = (int)filesize($indexFile);
		}

		// Measure meta file
		$metaFile = $collectionPath . '/.meta.json';
		if (file_exists($metaFile)) {
			$metaBytes = (int)filesize($metaFile);
		}

		// Measure object files (skip dot-prefixed files)
		$jsonFiles = glob($collectionPath . '/*.json');
		if (is_array($jsonFiles)) {
			foreach ($jsonFiles as $file) {
				$basename = basename($file);
				if (str_starts_with($basename, '.')) {
					continue;
				}

				$size = (int)filesize($file);
				$objectCount++;
				$objectBytes += $size;

				if ($size > $largestBytes) {
					$largestBytes = $size;
				}
			}
		}

		$avgObjectBytes = $objectCount > 0 ? (int)round($objectBytes / $objectCount) : 0;

		return [
			'collection_id'              => $collection->id,
			'collection_name'            => $collection->name,
			'schema'                     => $collection->schema,
			'object_count'               => $objectCount,
			'object_bytes'               => $objectBytes,
			'object_bytes_human'         => $this->formatBytes($objectBytes),
			'index_bytes'                => $indexBytes,
			'index_bytes_human'          => $this->formatBytes($indexBytes),
			'meta_bytes'                 => $metaBytes,
			'meta_bytes_human'           => $this->formatBytes($metaBytes),
			'avg_object_bytes'           => $avgObjectBytes,
			'avg_object_bytes_human'     => $this->formatBytes($avgObjectBytes),
			'largest_object_bytes'       => $largestBytes,
			'largest_object_bytes_human' => $this->formatBytes($largestBytes),
		];
	}

	/**
	 * Calculate memory recommendations per backend.
	 *
	 * @param array<string,mixed> $scanResults
	 *
	 * @return array<string,mixed>
	 */
	private function calculateRecommendations(array $scanResults): array
	{
		$totalBytes      = (int)$scanResults['total_bytes'];
		$totalObjects    = (int)$scanResults['total_objects'];
		$collectionCount = (int)$scanResults['collection_count'];

		// Estimate total cache entries: objects + indexes (1 per collection) + schemas + ~50 misc
		$estimatedEntries = $totalObjects + $collectionCount + $collectionCount + 50;

		// Base memory: raw data * serialization overhead
		$baseMemory = (int)round($totalBytes * self::SERIALIZATION_OVERHEAD);

		// Detect tiered caching: APCu (L1) + network cache (L2) are both available
		$hasL1          = $this->apcuService->isAvailable();
		$hasL2          = $this->redisService->isAvailable() || $this->memcachedService->isAvailable();
		$hasTieredCache = $hasL1 && $hasL2;

		$backends = [];

		// APCu recommendations
		$apcuOverhead     = $estimatedEntries * self::APCU_ENTRY_OVERHEAD;
		$apcuRecommended  = $baseMemory + $apcuOverhead;

		// With tiered caching, APCu can be sized tighter since Redis/Memcached
		// acts as a safety net for evicted entries. Round to nearest standard value
		// instead of rounding up aggressively.
		$apcuAllocation   = $hasTieredCache
			? $this->roundToNearestAllocation($apcuRecommended)
			: $this->roundToAllocation($apcuRecommended);
		$apcuAllocated    = $this->getApcuAllocatedMemory();
		// Compare at MB level to avoid false negatives from APCu internal overhead
		$allocatedMb      = (int)round($apcuAllocated / (1024 * 1024));
		$allocationMb     = (int)round($apcuAllocation / (1024 * 1024));
		$apcuSufficient   = $allocatedMb >= $allocationMb;

		$backends['apcu'] = [
			'name'                     => 'APCu (L1)',
			'installed'                => $this->apcuService->isInstalled(),
			'available'                => $this->apcuService->isAvailable(),
			'recommended_bytes'        => $apcuRecommended,
			'recommended_human'        => $this->formatBytes($apcuRecommended),
			'recommended_allocation'   => $this->formatAllocation($apcuAllocation),
			'current_allocated_bytes'  => $apcuAllocated,
			'current_allocated_human'  => $apcuAllocated > 0 ? $this->formatBytes($apcuAllocated) : 'N/A',
			'sufficient'               => $apcuAllocated > 0 ? $apcuSufficient : null,
			'entry_overhead'           => self::APCU_ENTRY_OVERHEAD,
		];

		// Redis recommendations
		$redisOverhead     = $estimatedEntries * self::REDIS_ENTRY_OVERHEAD;
		$redisRecommended  = $baseMemory + $redisOverhead;

		// With tiered caching, the L2 backend should be sized generously to provide
		// overflow capacity and restart resilience. Use 2x the calculated need.
		$redisAllocation   = $hasTieredCache
			? $this->roundToAllocation($redisRecommended * 2)
			: $this->roundToAllocation($redisRecommended);

		$backends['redis'] = [
			'name'                   => $hasTieredCache ? 'Redis (L2)' : 'Redis',
			'installed'              => $this->redisService->isInstalled(),
			'available'              => $this->redisService->isAvailable(),
			'recommended_bytes'      => $redisRecommended,
			'recommended_human'      => $this->formatBytes($redisRecommended),
			'recommended_allocation' => $this->formatAllocation($redisAllocation),
			'entry_overhead'         => self::REDIS_ENTRY_OVERHEAD,
		];

		// Memcached recommendations
		$memcachedOverhead     = $estimatedEntries * self::MEMCACHED_ENTRY_OVERHEAD;
		$memcachedRecommended  = $baseMemory + $memcachedOverhead;

		// Same tiered logic as Redis
		$memcachedAllocation   = $hasTieredCache
			? $this->roundToAllocation($memcachedRecommended * 2)
			: $this->roundToAllocation($memcachedRecommended);

		$backends['memcached'] = [
			'name'                   => $hasTieredCache ? 'Memcached (L2)' : 'Memcached',
			'installed'              => $this->memcachedService->isInstalled(),
			'available'              => $this->memcachedService->isAvailable(),
			'recommended_bytes'      => $memcachedRecommended,
			'recommended_human'      => $this->formatBytes($memcachedRecommended),
			'recommended_allocation' => $this->formatAllocation($memcachedAllocation),
			'entry_overhead'         => self::MEMCACHED_ENTRY_OVERHEAD,
		];

		$hasMemoryCache = $hasL1 || $hasL2;

		$result = [
			'estimated_entries'       => $estimatedEntries,
			'estimated_entries_human' => number_format($estimatedEntries),
			'base_memory'             => $baseMemory,
			'base_memory_human'       => $this->formatBytes($baseMemory),
			'backends'                => $backends,
			'has_memory_cache'        => $hasMemoryCache,
		];

		if (!$hasMemoryCache) {
			$result['filesystem_only_warning'] = true;
		}

		return $result;
	}

	/**
	 * Generate copy-pasteable configuration examples.
	 *
	 * @param array<string,mixed> $recommendations
	 *
	 * @return array<string,mixed>
	 */
	private function generateConfigExamples(array $recommendations): array
	{
		/** @var array<string,array<string,mixed>> $backends */
		$backends = $recommendations['backends'] ?? [];
		$examples = [];

		// APCu config example
		$apcuAllocation   = $backends['apcu']['recommended_allocation'] ?? '64M';
		$examples['apcu'] = [
			'name'     => 'APCu (php.ini)',
			'file'     => 'php.ini',
			'note'     => 'Add these settings to your php.ini file and restart PHP/Apache. APCu is the recommended cache for single-server setups.',
			'config'   => "apc.enabled=1\napc.shm_size={$apcuAllocation}\napc.ttl=7200\napc.gc_ttl=3600\napc.enable_cli=0",
		];

		// Redis config example
		$redisAllocation   = $backends['redis']['recommended_allocation'] ?? '64M';
		$redisMb           = (int)str_replace('M', '', (string)$redisAllocation);
		$redisBytes        = $redisMb * 1024 * 1024;
		$examples['redis'] = [
			'name'     => 'Redis (redis.conf)',
			'file'     => '/etc/redis/redis.conf',
			'note'     => 'Add these settings to your Redis configuration file and restart the Redis service.',
			'config'   => "maxmemory {$redisBytes}\nmaxmemory-policy allkeys-lru",
		];

		// Memcached config example
		$memcachedAllocation   = $backends['memcached']['recommended_allocation'] ?? '64M';
		$memcachedMb           = (int)str_replace('M', '', (string)$memcachedAllocation);
		$examples['memcached'] = [
			'name'     => 'Memcached (startup command)',
			'file'     => '/etc/memcached.conf or startup command',
			'note'     => 'Use the -m flag to set memory limit in MB when starting Memcached.',
			'config'   => "memcached -m {$memcachedMb} -p 11211 -u memcache -l 127.0.0.1",
		];

		// Installation instructions if no memory cache
		if (!($recommendations['has_memory_cache'] ?? false)) {
			$examples['install'] = [
				'name'     => 'Install APCu (Recommended)',
				'file'     => 'Terminal',
				'note'     => 'APCu is the easiest memory cache to install. It requires no external services and provides excellent performance for single-server setups.',
				'config'   => "# Ubuntu/Debian\nsudo apt-get install php-apcu\nsudo systemctl restart apache2\n\n# CentOS/RHEL\nsudo yum install php-pecl-apcu\nsudo systemctl restart httpd\n\n# cPanel/WHM\n# Install via EasyApache 4 > PHP Extensions > apcu",
			];
		}

		return $examples;
	}

	/**
	 * Format bytes into human-readable string.
	 */
	private function formatBytes(int $bytes): string
	{
		if ($bytes === 0) {
			return '0 B';
		}

		$units = ['B', 'KB', 'MB', 'GB'];
		$i     = 0;
		$value = (float)$bytes;

		while ($value >= 1024 && $i < count($units) - 1) {
			$value /= 1024;
			$i++;
		}

		return round($value, 1) . ' ' . $units[$i];
	}

	/**
	 * Format bytes as a config-file allocation string (e.g., "64M").
	 */
	private function formatAllocation(int $bytes): string
	{
		$mb = (int)ceil($bytes / (1024 * 1024));

		return $mb . 'M';
	}

	/**
	 * Round up to a sensible power-of-2 MB allocation (minimum 32M).
	 */
	private function roundToAllocation(int $bytes): int
	{
		$mb = (int)ceil($bytes / (1024 * 1024));

		// Minimum 32MB
		if ($mb <= 32) {
			return self::MIN_ALLOCATION;
		}

		// Round up to next power of 2
		$power = (int)ceil(log($mb, 2));

		return (int)2 ** $power * 1024 * 1024;
	}

	/**
	 * Round to the nearest power-of-2 MB allocation (minimum 32M).
	 * Used for L1 (APCu) when tiered caching is active — sizes tighter
	 * since L2 catches any overflow from eviction.
	 */
	private function roundToNearestAllocation(int $bytes): int
	{
		$mb = (int)ceil($bytes / (1024 * 1024));

		// Minimum 32MB
		if ($mb <= 32) {
			return self::MIN_ALLOCATION;
		}

		// Round to nearest power of 2 (not always up)
		$power     = log($mb, 2);
		$lower     = (int)floor($power);
		$upper     = (int)ceil($power);
		$lowerVal  = (int)2 ** $lower;
		$upperVal  = (int)2 ** $upper;

		// Pick whichever is closer to the actual need
		$nearest = ($mb - $lowerVal) <= ($upperVal - $mb) ? $lowerVal : $upperVal;

		// Don't go below 32MB
		return max($nearest, 32) * 1024 * 1024;
	}

	/**
	 * Get currently allocated APCu memory from stats.
	 */
	private function getApcuAllocatedMemory(): int
	{
		if (!$this->apcuService->isAvailable()) {
			return 0;
		}

		$stats = $this->apcuService->getStats();

		return (int)($stats['memory_total'] ?? 0);
	}
}

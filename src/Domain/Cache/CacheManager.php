<?php

namespace TotalCMS\Domain\Cache;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\CacheInterface;
use TotalCMS\Domain\Cache\Service\CacheInvalidationSignal;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\SystemEventPayload;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Strategic cache manager that routes different data types to optimal cache services.
 */
class CacheManager
{
	// Cache key prefixes
	public const PREFIX_COMPUTED        = 'computed';
	public const PREFIX_COLLECTION      = 'collection';
	public const PREFIX_API_RESPONSE    = 'api';
	public const PREFIX_SESSION         = 'session';
	public const PREFIX_TEMPLATE        = 'template';
	public const PREFIX_PASSWORD_RESET  = 'password_reset';

	// Array of all cache types for clearByType functionality
	public const CACHE_TYPES = [
		self::PREFIX_COMPUTED,
		self::PREFIX_COLLECTION,
		self::PREFIX_API_RESPONSE,
		self::PREFIX_SESSION,
		self::PREFIX_TEMPLATE,
	];

	// Cache TTL constants for different data types
	// Cross-process cache invalidation (CacheInvalidationSignal) handles freshness;
	// TTLs are a safety net, not the primary invalidation mechanism.
	public const DEFAULT_TTL             = 7200;              // 2 hours - default TTL for most data
	public const TTL_COLLECTIONS_LIST    = 7200;      // 2 hours - invalidated explicitly on collection changes
	public const TTL_INDEX_DATA          = 14400;          // 4 hours - invalidated explicitly on index rebuild
	public const TTL_OBJECT_IDS          = 14400;           // 4 hours - invalidated explicitly on object add/remove
	public const TTL_OBJECT_DATA         = 14400;          // 4 hours - individual objects change infrequently
	public const TTL_RESERVED_SCHEMAS    = 86400;     // 24 hours - reserved schemas NEVER change
	public const TTL_RESERVED_SCHEMA_IDS = 86400;  // 24 hours - reserved schema IDs NEVER change
	public const TTL_CUSTOM_SCHEMA       = 14400;        // 4 hours - custom schemas change infrequently
	public const TTL_FLATTENED_SCHEMA    = 14400;       // 4 hours - flattened schemas (inheritance resolved)
	public const TTL_API_RESPONSE        = 7200;          // 2 hours - invalidated explicitly on collection changes
	public const TTL_SESSION_DATA        = 1440;         // 24 minutes - session timeout buffer (unchanged)
	public const TTL_PASSWORD_RESET      = 1800;        // 30 minutes - password reset tokens (unchanged)
	private readonly string $domainPrefix;
	private readonly LoggerInterface $logger;

	/** @var array<string,CacheInterface> Available cache services */
	private array $cacheServices = [];

	/**
	 * In-memory flag to bypass cache reads for current process.
	 * When true, getData() returns null, forcing fresh filesystem reads.
	 * Cache writes still occur to warm shared caches.
	 */
	private bool $cacheDisabled = false;

	/**
	 * When true, suppress writing to the invalidation signal file.
	 * Used during replay to prevent infinite recursion.
	 */
	private bool $suppressSignals = false;

	/** Whether this process is CLI (signals only needed from CLI → web). */
	private readonly bool $isCli;

	public function __construct(
		private readonly FilesystemService $filesystemService,
		private readonly OPcacheService $opcacheService,
		private readonly RedisService $redisService,
		private readonly MemcachedService $memcachedService,
		private readonly APCuService $apcuService,
		private readonly WatermarkCleanupService $watermarkCleanupService,
		private readonly DevModeManager $devModeManager,
		private readonly CacheInvalidationSignal $invalidationSignal,
		private readonly EventDispatcher $eventDispatcher,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('cachemanager');
		// Initialize cache services and version
		$this->cacheServices = [
			'filesystem' => $this->filesystemService,
			'opcache'    => $this->opcacheService,
			'redis'      => $this->redisService,
			'memcached'  => $this->memcachedService,
			'apcu'       => $this->apcuService,
		];

		// Create domain-specific prefix to prevent cache collisions between installations
		$this->domainPrefix = md5($this->config->domain);
		$this->isCli        = php_sapi_name() === 'cli';
	}

	/**
	 * Disable cache reads for the current process.
	 * Useful for CLI scripts that need fresh data on every read.
	 * This is in-memory only and does not affect other processes.
	 *
	 * Note: Cache writes still occur to warm shared caches (Redis, filesystem)
	 * with fresh data that the web server can use.
	 */
	public function disableCache(): void
	{
		$this->cacheDisabled = true;
	}

	/**
	 * Re-enable caching for the current process.
	 */
	public function enableCache(): void
	{
		$this->cacheDisabled = false;
	}

	/**
	 * Check if caching is currently disabled for this process.
	 */
	public function isCacheDisabled(): bool
	{
		return $this->cacheDisabled;
	}

	/**
	 * Control whether invalidation signals are written.
	 * Used by CacheInvalidationMiddleware during replay to prevent recursion.
	 */
	public function setSuppressSignals(bool $suppress): void
	{
		$this->suppressSignals = $suppress;
	}

	/**
	 * Create a domain-specific cache key to prevent collisions between installations.
	 */
	private function createDomainKey(string $key): string
	{
		return $this->domainPrefix . ':' . $key;
	}

	/**
	 * Apply this process's domain prefix to an unprefixed cache key.
	 * Used by CacheInvalidationMiddleware to re-prefix keys from CLI signals.
	 */
	public function applyDomainPrefix(string $key): string
	{
		return $this->createDomainKey($key);
	}

	/**
	 * Strip the domain prefix from a fully-qualified cache key.
	 * Used when signaling keys for cross-process invalidation so the
	 * receiving process can re-apply its own correct domain prefix.
	 */
	private function stripDomainPrefix(string $key): string
	{
		$prefix = $this->domainPrefix . ':';
		if (str_starts_with($key, $prefix)) {
			return substr($key, strlen($prefix));
		}

		return $key;
	}

	/**
	 * Store collection index data (fast access needed, can be large).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $index
	 */
	public function storeCollectionIndex(string $collectionName, array $index, int $ttl = self::TTL_INDEX_DATA): bool
	{
		$key = $this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}");

		return $this->storeData($key, $index, $ttl);
	}

	public function getCacheDirectory(): string
	{
		return $this->filesystemService->getCachDir();
	}

	/**
	 * Retrieve collection index data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getCollectionIndex(string $collectionName): ?array
	{
		return $this->getData($this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}"));
	}

	/**
	 * Store API response data (fast access, medium TTL).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $params
	 */
	public function storeApiResponse(string $endpoint, array $params, mixed $response, int $ttl = self::TTL_API_RESPONSE): bool
	{
		$key = $this->createDomainKey(self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params)));

		return $this->storeData($key, $response, $ttl);
	}

	/**
	 * Retrieve API response data.
	 *
	 * @param array<string,mixed> $params
	 */
	public function getApiResponse(string $endpoint, array $params): mixed
	{
		$key = $this->createDomainKey(self::PREFIX_API_RESPONSE . ':' . md5($endpoint . serialize($params)));

		return $this->getData($key);
	}

	/** Store computed/expensive operations (can be large, longer TTL). */
	public function storeComputedData(string $key, mixed $data, int $ttl = self::TTL_CUSTOM_SCHEMA): bool
	{
		$cacheKey = $this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}");

		return $this->storeData($cacheKey, $data, $ttl);
	}

	public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// Note: We always store data even when cacheDisabled or devMode is active.
		// These flags only bypass reads - we still want to populate cache with fresh data
		// so that when the flags are turned off, the cache is warm and accurate.

		// L1/L2 tiered caching: write to both APCu (L1) and a network cache (L2) when available.
		// APCu is fastest (shared memory, no network hop) but local to a single server and
		// cleared on PHP-FPM restart. Redis/Memcached survive restarts and serve as a warm
		// backup that prevents cold-start cache misses.
		$stored = false;

		// L1: APCu (fastest, local memory)
		if ($this->apcuService->isAvailable()) {
			$stored = $this->apcuService->set($key, $data, $ttl);
		}

		// L2: Network cache (survives restarts, larger capacity)
		if ($this->redisService->isAvailable()) {
			$stored = $this->redisService->set($key, $data, $ttl) || $stored;
		} elseif ($this->memcachedService->isAvailable()) {
			$stored = $this->memcachedService->set($key, $data, $ttl) || $stored;
		}

		// Fallback to filesystem cache only if no memory caches available
		if (!$stored && $this->filesystemService->isAvailable()) {
			$stored = $this->filesystemService->set($key, $data, $ttl);
		}

		return $stored;
	}

	public function getData(string $key): mixed
	{
		// Skip cache reads when cache is disabled or dev mode is active
		if ($this->cacheDisabled || $this->devModeManager->isDevModeActive()) {
			return null;
		}

		$apcuAvailable = $this->apcuService->isAvailable();

		// L1: Check APCu first (fastest, local memory)
		if ($apcuAvailable) {
			$result = $this->apcuService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		// L2: Check network caches (Redis, then Memcached)
		// On hit, promote back to L1 (APCu) so subsequent requests are fast
		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($key);
			if ($result !== null) {
				if ($apcuAvailable) {
					$this->apcuService->set($key, $result, self::DEFAULT_TTL);
				}

				return $result;
			}
		}

		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($key);
			if ($result !== null) {
				if ($apcuAvailable) {
					$this->apcuService->set($key, $result, self::DEFAULT_TTL);
				}

				return $result;
			}
		}

		// Check filesystem cache
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($key);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	public function clearData(string $key): bool
	{
		$success = true;

		// Delete from all available cache backends
		if ($this->apcuService->isAvailable()) {
			$success &= $this->apcuService->delete($key);
		}

		if ($this->redisService->isAvailable()) {
			$success &= $this->redisService->delete($key);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->memcachedService->delete($key);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->filesystemService->delete($key);
		}

		// Always clear OPcache to ensure no stale cached data
		if ($this->opcacheService->isAvailable()) {
			$success &= $this->opcacheService->clear();
		}

		// Signal for cross-process invalidation (CLI → web)
		// Strip domain prefix so the web process can re-apply its own correct prefix
		if ($this->isCli && !$this->suppressSignals) {
			$this->invalidationSignal->signal($this->stripDomainPrefix($key));
		}

		return (bool)$success;
	}

	/**
	 * Retrieve computed data with cache warming.
	 */
	public function getComputedData(string $key): mixed
	{
		return $this->getData($this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}"));
	}

	/**
	 * Delete computed data from all cache backends.
	 */
	public function clearComputedData(string $key): bool
	{
		return $this->clearData($this->createDomainKey(self::PREFIX_COMPUTED . ":{$key}"));
	}

	/**
	 * Clear all computed data (useful for clearing schema caches).
	 */
	public function clearAllComputedData(): bool
	{
		return $this->clearByType(self::PREFIX_COMPUTED);
	}

	/**
	 * Clear collection index cache from all backends.
	 * Clears the index data, object IDs, and API response caches for the collection.
	 */
	public function clearCollectionIndex(string $collectionName): bool
	{
		// Clear the collection index stored by storeCollectionIndex()
		$collectionCleared = $this->clearData(
			$this->createDomainKey(self::PREFIX_COLLECTION . ":{$collectionName}")
		);

		// Clear related computed caches
		$indexCleared     = $this->clearComputedData("index:{$collectionName}");
		$objectIdsCleared = $this->clearComputedData("object_ids:{$collectionName}");

		// Clear cached API/query responses that depend on this collection's data
		$this->clearByType(self::PREFIX_API_RESPONSE);

		return $collectionCleared || $indexCleared || $objectIdsCleared;
	}

	/**
	 * Store session data (fast access, APCu preferred for single-server deployments).
	 * Priority: APCu > Redis > Memcached > Filesystem.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storeSessionData(string $sessionId, array $data, int $ttl = self::TTL_SESSION_DATA): bool
	{
		$key = $this->createDomainKey(self::PREFIX_SESSION . ":{$sessionId}");

		return $this->storeData($key, $data, $ttl);
	}

	/**
	 * Retrieve session data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getSessionData(string $sessionId): ?array
	{
		return $this->getData($this->createDomainKey(self::PREFIX_SESSION . ":{$sessionId}"));
	}

	/**
	 * Store template compilation results (large files, should persist).
	 * Priority: Filesystem only (OPcache handles the compiled PHP automatically).
	 */
	public function storeCompiledTemplate(string $templateName, string $compiledCode): bool
	{
		// Skip caching entirely when development mode is active
		if ($this->devModeManager->isDevModeActive()) {
			return false;
		}

		if ($this->filesystemService->isAvailable()) {
			$key = $this->createDomainKey(self::PREFIX_TEMPLATE . ":{$templateName}");

			return $this->filesystemService->set($key, $compiledCode, 0); // No TTL for templates
		}

		return false;
	}

	/**
	 * Clear all cache entries of a specific type.
	 *
	 * @param string $type Cache type prefix (use CACHE_TYPES constants)
	 *
	 * @return bool Success status
	 */
	public function clearByType(string $type): bool
	{
		// Validate type
		if (!in_array($type, self::CACHE_TYPES, true)) {
			return false;
		}

		$pattern = $this->domainPrefix . ':' . $type . ':*';
		$success = $this->clearByPatternAllBackends($pattern);

		// Signal for cross-process invalidation (CLI → web)
		// Strip domain prefix so the web process can re-apply its own correct prefix
		if ($this->isCli && !$this->suppressSignals) {
			$this->invalidationSignal->signalPattern($this->stripDomainPrefix($pattern));
		}

		return $success;
	}

	/**
	 * Clear cache entries by pattern across all available backends.
	 */
	public function clearByPatternAllBackends(string $pattern): bool
	{
		$success = true;

		if ($this->apcuService->isAvailable()) {
			$success &= $this->clearByPattern($this->apcuService, $pattern);
		}

		if ($this->redisService->isAvailable()) {
			$success &= $this->clearByPattern($this->redisService, $pattern);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->clearByPattern($this->memcachedService, $pattern);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->clearByPattern($this->filesystemService, $pattern);
		}

		return (bool)$success;
	}

	/**
	 * Clear cache entries by pattern for a specific cache service.
	 */
	private function clearByPattern(CacheInterface $service, string $pattern): bool
	{
		// Check if the service supports pattern-based clearing
		if ($service instanceof RedisService || $service instanceof MemcachedService || $service instanceof FilesystemService || $service instanceof APCuService) {
			return $service->clearByPattern($pattern);
		}

		// For other services, fallback to clearing everything
		// This ensures cache is cleared when needed, though not optimal
		return $service->clear();
	}

	/**
	 * Check if the app version has changed and clear all caches if so.
	 * Used to automatically clear stale caches after a Total CMS update.
	 */
	public function clearIfVersionChanged(): bool
	{
		if (!$this->filesystemService->isAvailable()) {
			return false;
		}

		$appVersionFile = $this->filesystemService->getCachDir() . '/.app_version';
		$currentVersion = Version::get();
		$storedVersion  = is_file($appVersionFile) ? trim((string)file_get_contents($appVersionFile)) : '';

		if ($storedVersion === $currentVersion) {
			return false;
		}

		$this->logger->info('App version changed, clearing all caches', [
			'previous' => $storedVersion,
			'current'  => $currentVersion,
		]);

		$this->clearAllCaches();
		file_put_contents($appVersionFile, $currentVersion);

		return true;
	}

	/**
	 * Store license data - MANDATORY caching that cannot be disabled.
	 * License data doesn't change frequently and hitting license server on every request is bad for performance.
	 * This method bypasses all cache disabled settings to prevent rate limit cascades.
	 *
	 * IMPORTANT: License data is stored in BOTH memory cache AND filesystem.
	 * Memory cache provides fast access, filesystem provides persistent backup
	 * that survives memory cache eviction or server restarts.
	 */
	public function storeLicenseData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		// License caching is MANDATORY - bypasses all cache disabled settings
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey    = $this->createDomainKey($key);
		$memoryStored = false;

		// Store as plain array to avoid unserialize(allowed_classes:false) issues
		// which would turn LicenseData into __PHP_Incomplete_Class and break cache reads
		$arrayData = $data instanceof LicenseData ? $data->toArray() : $data;

		// Store in memory cache (fastest) - use isInstalled() to bypass config disabled checks
		// Try each memory cache in priority order until one succeeds
		if ($this->apcuService->isInstalled()) {
			$memoryStored = $this->apcuService->set($domainKey, $arrayData, $ttl);
		}

		if (!$memoryStored && $this->redisService->isInstalled()) {
			$memoryStored = $this->redisService->set($domainKey, $arrayData, $ttl);
		}

		if (!$memoryStored && $this->memcachedService->isInstalled()) {
			$memoryStored = $this->memcachedService->set($domainKey, $arrayData, $ttl);
		}

		// ALWAYS store in filesystem as persistent backup, even when the user
		// has disabled filesystem caching in config — license data has to
		// survive memory eviction or restarts, and disabling caches in dev
		// otherwise causes API calls on every request and rate-limit cascades.
		// `setMandatory` bypasses the enabled flag but still respects writability.
		$filesystemStored = $this->filesystemService->setMandatory($domainKey, $arrayData, $ttl);

		// Success if stored in at least one location
		return $memoryStored || $filesystemStored;
	}

	/**
	 * Get license data - MANDATORY caching that cannot be disabled.
	 * This method bypasses all cache disabled settings to prevent rate limit cascades.
	 */
	public function getLicenseData(string $key): ?LicenseData
	{
		// License caching is MANDATORY - bypasses all cache disabled settings
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);
		$result    = null;

		// Check memory caches first (fastest)
		// Use isInstalled() instead of isAvailable() to bypass config disabled checks
		if ($this->apcuService->isInstalled()) {
			$result = $this->apcuService->get($domainKey);
		}

		if ($result === null && $this->redisService->isInstalled()) {
			$result = $this->redisService->get($domainKey);
		}

		if ($result === null && $this->memcachedService->isInstalled()) {
			$result = $this->memcachedService->get($domainKey);
		}

		// Filesystem is ALWAYS checked as absolute fallback for license data,
		// even when filesystem caching is disabled in config (see comment in
		// storeLicenseData() for why). `getMandatory` bypasses the enabled flag.
		if ($result === null) {
			$result = $this->filesystemService->getMandatory($domainKey);
		}

		// Reconstruct LicenseData from cached array
		if (is_array($result) && isset($result['valid'], $result['domain'])) {
			return LicenseData::fromArray($result);
		}

		// Handle legacy cached LicenseData objects (from before this fix)
		if ($result instanceof LicenseData) {
			return $result;
		}

		return null;
	}

	/**
	 * Clear license data - clears from all installed backends regardless of config.
	 */
	public function clearLicenseData(string $key): bool
	{
		// Use domain-specific key to prevent license data sharing between sites
		$domainKey = $this->createDomainKey($key);
		$success   = true;

		// Delete from all installed cache backends (bypasses config disabled checks)
		if ($this->apcuService->isInstalled()) {
			$success &= $this->apcuService->delete($domainKey);
		}

		if ($this->redisService->isInstalled()) {
			$success &= $this->redisService->delete($domainKey);
		}

		if ($this->memcachedService->isInstalled()) {
			$success &= $this->memcachedService->delete($domainKey);
		}

		// Always clear from filesystem, even when filesystem caching is
		// disabled — otherwise stale license data could persist on disk.
		$success &= $this->filesystemService->deleteMandatory($domainKey);

		return (bool)$success;
	}

	/**
	 * Store password reset data - bypasses dev mode and cache clearing.
	 * Similar to license data, password reset tokens should persist regardless of dev mode
	 * and should NOT be cleared when users clear cache through the admin interface.
	 *
	 * @param array<string,mixed> $data
	 */
	public function storePasswordResetData(string $key, array $data, int $ttl = self::TTL_PASSWORD_RESET): bool
	{
		// Always cache password reset data regardless of dev mode
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);

		// Priority: APCu > Redis > Memcached > Filesystem (single cache layer only)
		if ($this->apcuService->isAvailable()) {
			return $this->apcuService->set($domainKey, $data, $ttl);
		}

		if ($this->redisService->isAvailable()) {
			return $this->redisService->set($domainKey, $data, $ttl);
		}

		if ($this->memcachedService->isAvailable()) {
			return $this->memcachedService->set($domainKey, $data, $ttl);
		}

		// Fallback to filesystem cache only if no memory caches available
		if ($this->filesystemService->isAvailable()) {
			return $this->filesystemService->set($domainKey, $data, $ttl);
		}

		return false;
	}

	/**
	 * Get password reset data - bypasses dev mode.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getPasswordResetData(string $key): ?array
	{
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);

		// Check memory caches first (fastest)
		if ($this->apcuService->isAvailable()) {
			$result = $this->apcuService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->redisService->isAvailable()) {
			$result = $this->redisService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		if ($this->memcachedService->isAvailable()) {
			$result = $this->memcachedService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		// Check filesystem cache
		if ($this->filesystemService->isAvailable()) {
			$result = $this->filesystemService->get($domainKey);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Clear password reset data - bypasses dev mode.
	 */
	public function clearPasswordResetData(string $key): bool
	{
		// Use domain-specific key to prevent data sharing between sites
		$domainKey = $this->createDomainKey(self::PREFIX_PASSWORD_RESET . ':' . $key);
		$success   = true;

		// Delete from all available cache backends
		if ($this->apcuService->isAvailable()) {
			$success &= $this->apcuService->delete($domainKey);
		}

		if ($this->redisService->isAvailable()) {
			$success &= $this->redisService->delete($domainKey);
		}

		if ($this->memcachedService->isAvailable()) {
			$success &= $this->memcachedService->delete($domainKey);
		}

		if ($this->filesystemService->isAvailable()) {
			$success &= $this->filesystemService->delete($domainKey);
		}

		return (bool)$success;
	}

	/**
	 * Clear all caches including OPcache and text watermark cache.
	 *
	 * @return array<string,mixed> Status information about what was cleared
	 */
	public function clearAllCaches(): array
	{
		$results        = [];
		$overallSuccess = true;

		// Preserve license data before clearing (critical for system operation)
		$licenseData = $this->getLicenseData(LicenseData::CACHE_KEY);

		// Clear all available cache services
		foreach ($this->cacheServices as $name => $service) {
			if (!$service->isAvailable()) {
				$results[$name] = ['cleared' => false, 'reason' => 'not available'];
				continue;
			}

			try {
				$cleared        = $service->clear();
				$results[$name] = ['cleared' => $cleared, 'reason' => $cleared ? 'success' : 'failed'];
				$overallSuccess = $overallSuccess && $cleared;
			} catch (\Exception $e) {
				$results[$name] = ['cleared' => false, 'reason' => $e->getMessage()];
				$overallSuccess = false;
			}
		}

		// Restore license data after clearing
		if ($licenseData instanceof LicenseData) {
			$this->storeLicenseData(LicenseData::CACHE_KEY, $licenseData, LicenseData::CACHE_STORAGE_TTL);
			$results['license'] = ['preserved' => true, 'reason' => 'restored after clear'];
		}

		// Clear text watermark cache (clear all cached watermarks)
		try {
			$this->watermarkCleanupService->clearOldCache(0); // Clear all watermarks regardless of age
			$results['watermarks'] = ['cleared' => true, 'reason' => 'success'];
		} catch (\Exception $e) {
			// Log error but don't fail the entire cache clear operation
			$this->logger->warning('Failed to clear text watermark cache', [
				'error'     => $e->getMessage(),
				'exception' => $e::class,
			]);
			$results['watermarks'] = ['cleared' => false, 'reason' => $e->getMessage()];
			$overallSuccess        = false;
		}

		$results['success'] = $overallSuccess;

		// Signal for cross-process invalidation (CLI → web)
		if ($this->isCli && !$this->suppressSignals) {
			$this->invalidationSignal->signalFull();
		}

		$this->eventDispatcher->dispatch('cache.cleared', new SystemEventPayload($results));

		return $results;
	}
}

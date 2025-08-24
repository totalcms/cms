<?php

namespace TotalCMS\Infrastructure\Diagnostics;

use TotalCMS\Domain\Bundle\Service\BundleChecker;
use TotalCMS\Support\Config;

/**
 * Run tests against the system to.
 */
class ServerChecker
{
	private const REQUIRED_SOFTWARE = [
		'curl',
		'exif',
		'fileinfo',
		'gd',
		// 'intl',
		'json',
		'mbstring',
		'openssl',
		// 'pdo',
	];
	private const OPTIONAL_SOFTWARE = [
		'imagick',
		'opcache',
		'apcu',
		'redis',
		'memcached',
	];
	private const PHP_VERSION = '8.2.0';

	public function __construct(
		private readonly BundleChecker $bundleChecker,
		private readonly Config $config,
	) {
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed>
	 * */
	public function serverInfo(): array
	{
		// Get the server information
		$info = [
			'Total CMS Version'  => $this->getVersion(),
			'PHP Version'        => PHP_VERSION,
			'Operating System'   => PHP_OS,
			'Web Server'         => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
			'Domain'             => $_SERVER['SERVER_NAME'] ?? 'Unknown',
			'Document Root'      => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
			'Max POST Size'      => ini_get('post_max_size'),
			'Max Upload Size'    => ini_get('upload_max_filesize'),
			'Max Execution Time' => ini_get('max_execution_time'),
			'Memory Limit'       => ini_get('memory_limit'),
			'Timezone'           => date_default_timezone_get(),
			'Total Space'        => $this->totalspace(),
			'Free Space'         => $this->freespace(),
			// @phpstan-ignore argument.type
			'Locale' => setlocale(LC_ALL, 0),
		];

		// Add cache-specific information
		$info = array_merge($info, $this->getCacheInfo());

		// Add GD-specific information
		$info = array_merge($info, $this->getGDInfo());

		return $info;
	}

	public function totalspace(): string
	{
		return $this->formatBytes(intval(disk_total_space(__DIR__)));
	}

	public function freespace(): string
	{
		return $this->formatBytes(intval(disk_free_space(__DIR__)));
	}

	public function cacheDirSize(): string
	{
		$cache            = $this->config->cache ?? [];
		$filesystemConfig = $cache['filesystem'] ?? [];
		$dir              = $filesystemConfig['directory'] ?? '';
		$size             = 0;
		if (file_exists($dir)) {
			foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
				$size += $file->getSize();
			}
		}

		return $this->formatBytes($size);
	}

	private function formatBytes(int $bytes): string
	{
		$units     = ['B', 'KB', 'MB', 'GB', 'TB'];
		$unitCount = count($units);
		for ($i = 0; $bytes >= 1024 && $i < $unitCount - 1; $i++) {
			$bytes /= 1024;
		}

		return round($bytes, 2) . ' ' . $units[$i];
	}

	/** @return array<string,mixed> */
	public function getConfig(): array
	{
		return $this->config->toArray();
	}

	/** @return array<string,bool> */
	public function checkRequiredSoftware(): array
	{
		$software = [
			'Total CMS App Integrity'        => $this->bundleCheck(),
			'PHP ' . self::PHP_VERSION . '+' => version_compare(PHP_VERSION, self::PHP_VERSION, '>='),
		];
		foreach (self::REQUIRED_SOFTWARE as $requiredSoftware) {
			$software["PHP Extension: $requiredSoftware"] = extension_loaded($requiredSoftware);
		}

		// Check for GD with FreeType support
		if (extension_loaded('gd')) {
			$software['GD FreeType Support'] = function_exists('imageftbbox') && function_exists('imagettftext');
		}

		return $software;
	}

	/** @return array<string,bool> */
	public function checkOptionalSoftware(): array
	{
		$software = [];
		foreach (self::OPTIONAL_SOFTWARE as $optionalSoftware) {
			$software["PHP Extension: $optionalSoftware"] = $this->checkExtension($optionalSoftware);
		}

		return $software;
	}

	/**
	 * Get detailed information about optional software including recommendations.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getOptionalSoftwareDetails(): array
	{
		$details = [];

		foreach (self::OPTIONAL_SOFTWARE as $extension) {
			$details[$extension] = [
				'name'               => "PHP Extension: $extension",
				'available'          => $this->checkExtension($extension),
				'description'        => $this->getExtensionDescription($extension),
				'recommendation'     => $this->getExtensionRecommendation($extension),
				'performance_impact' => $this->getExtensionPerformanceImpact($extension),
			];
		}

		return $details;
	}

	/**
	 * Get description for an extension.
	 */
	private function getExtensionDescription(string $extension): string
	{
		return match ($extension) {
			'imagick'   => 'Advanced image processing library with support for 200+ image formats',
			'opcache'   => 'PHP bytecode cache that dramatically improves performance',
			'apcu'      => 'Fast in-memory user cache for single-server applications',
			'redis'     => 'In-memory data structure store for caching and session storage',
			'memcached' => 'High-performance, distributed memory object caching system',
			default     => 'Optional PHP extension',
		};
	}

	/**
	 * Get recommendation for when to use an extension.
	 */
	private function getExtensionRecommendation(string $extension): string
	{
		return match ($extension) {
			'imagick'   => 'Recommended for sites with heavy image processing, advanced image effects, or PDF generation needs',
			'opcache'   => 'Recommended for all production sites - provides 2-5x performance improvement with no downsides',
			'apcu'      => 'Recommended for most sites - zero-config caching that works immediately on single-server setups',
			'redis'     => 'Recommended for high-traffic sites needing advanced caching, session clustering, or real-time features',
			'memcached' => 'Recommended for high-traffic sites (1000+ daily visitors) or multi-server setups requiring shared caching',
			default     => 'Check documentation for specific use cases',
		};
	}

	/**
	 * Get performance impact information for an extension.
	 */
	private function getExtensionPerformanceImpact(string $extension): string
	{
		return match ($extension) {
			'imagick'   => 'High impact for image operations, no impact if not used',
			'opcache'   => 'Very high impact: 2-5x faster page loads, 50% less memory usage',
			'apcu'      => 'Medium-high impact: Much faster than filesystem cache, instant setup',
			'redis'     => 'Medium-high impact: Fast caching, improved session performance',
			'memcached' => 'Medium impact: Faster template caching, reduced database load',
			default     => 'Varies by usage',
		};
	}

	/**
	 * Enhanced extension checking with specific logic for cache extensions.
	 */
	private function checkExtension(string $extension): bool
	{
		switch ($extension) {
			case 'opcache':
				// OPcache has specific detection requirements and naming variations
				return (extension_loaded('opcache') || extension_loaded('Zend OPcache'))
					   && function_exists('opcache_get_status')
					   && opcache_get_status() !== false;

			case 'apcu':
				// APCu requires extension and basic functionality test
				return extension_loaded('apcu') && function_exists('apcu_store') && function_exists('apcu_fetch');

			case 'redis':
				// Redis requires both extension and class
				return extension_loaded('redis') && class_exists('Redis');

			case 'memcached':
				// Memcached requires both extension and class
				return extension_loaded('memcached') && class_exists('Memcached');

			default:
				// Standard extension check for others
				return extension_loaded($extension);
		}
	}

	/**
	 * Get cache-specific server information.
	 *
	 * @return array<string,string>
	 */
	private function getCacheInfo(): array
	{
		$cacheInfo = [];

		// OPcache information
		if (function_exists('opcache_get_status')) {
			$status = opcache_get_status(false);
			if ($status !== false) {
				$cacheInfo['OPcache Status'] = $status['opcache_enabled'] ? 'Enabled' : 'Disabled';
				if (isset($status['opcache_statistics']['opcache_hit_rate'])) {
					$hitRate                       = round($status['opcache_statistics']['opcache_hit_rate'], 2);
					$cacheInfo['OPcache Hit Rate'] = $hitRate . '%';
				}
				if (isset($status['memory_usage']['used_memory'])) {
					$usedMemory                       = round($status['memory_usage']['used_memory'] / 1024 / 1024, 2);
					$cacheInfo['OPcache Memory Used'] = $usedMemory . ' MB';
				}
			} else {
				$cacheInfo['OPcache Status'] = 'Available but not functioning';
			}
		}

		// APCu information
		if ($this->checkExtension('apcu')) {
			try {
				$testKey = 'tcms_server_check_' . uniqid();
				$testValue = 'test';
				
				if (apcu_store($testKey, $testValue, 1)) {
					$retrieved = apcu_fetch($testKey, $success);
					apcu_delete($testKey);
					
					if ($success && $retrieved === $testValue) {
						$cacheInfo['APCu Status'] = 'Working';
						
						// Get APCu cache info if available
						if (function_exists('apcu_cache_info')) {
							$info = apcu_cache_info(true); // Get info without entries list
							if (is_array($info) && isset($info['num_hits'], $info['num_misses'])) {
								$total = (int)$info['num_hits'] + (int)$info['num_misses'];
								if ($total > 0) {
									$hitRate = round(((int)$info['num_hits'] / $total) * 100, 2);
									$cacheInfo['APCu Hit Rate'] = $hitRate . '%';
								}
							}
						}
					} else {
						$cacheInfo['APCu Status'] = 'Extension available, functionality failed';
					}
				} else {
					$cacheInfo['APCu Status'] = 'Extension available, store failed';
				}
			} catch (\Exception $e) {
				$cacheInfo['APCu Status'] = 'Extension available, test failed';
			}
		}

		// Redis information
		if ($this->checkExtension('redis')) {
			try {
				$redis = new \Redis();
				$redis->connect('127.0.0.1', 6379, 1);
				$redis->ping();
				$cacheInfo['Redis Connection'] = 'Connected';
				$redis->close();
			} catch (\Exception $e) {
				$cacheInfo['Redis Connection'] = 'Extension available, connection failed';
			}
		}

		// Memcached information
		if ($this->checkExtension('memcached')) {
			try {
				$memcached = new \Memcached();
				$memcached->addServer('127.0.0.1', 11211);
				$memcached->set('test', 'test', 1);
				if ($memcached->get('test') === 'test') {
					$cacheInfo['Memcached Connection'] = 'Connected';
				} else {
					$cacheInfo['Memcached Connection'] = 'Extension available, connection failed';
				}
			} catch (\Exception $e) {
				$cacheInfo['Memcached Connection'] = 'Extension available, connection failed';
			}
		}

		return $cacheInfo;
	}

	/**
	 * Get GD-specific information including FreeType support.
	 *
	 * @return array<string,string>
	 */
	private function getGDInfo(): array
	{
		$gdInfo = [];

		if (function_exists('gd_info')) {
			$info                 = gd_info();
			$gdInfo['GD Version'] = $info['GD Version'] ?? 'Unknown';

			// Check FreeType support
			if (isset($info['FreeType Support'])) {
				$gdInfo['GD FreeType Support'] = $info['FreeType Support'] ? 'Yes' : 'No';
			}

			// Check specific functions that Total CMS uses
			$gdInfo['GD imageftbbox Function']  = function_exists('imageftbbox') ? 'Available' : 'Not Available';
			$gdInfo['GD imagettftext Function'] = function_exists('imagettftext') ? 'Available' : 'Not Available';

			// If FreeType is not available, explain the impact
			if (!function_exists('imageftbbox')) {
				$gdInfo['GD FreeType Impact'] = 'Image text generation will use basic fonts with limited sizing';
			}
		}

		return $gdInfo;
	}

	/**
	 * Get cache directory writability status.
	 */
	private function getCacheWritable(): ?bool
	{
		$cache            = $this->config->cache ?? [];
		$filesystemConfig = $cache['filesystem'] ?? [];
		$cacheDir         = $filesystemConfig['directory'] ?? '';

		if ($cacheDir === 'false' || empty($cacheDir)) {
			return null; // Cache is disabled
		}

		return is_writable($cacheDir);
	}

	/** @return array<string,bool> */
	public function checkPermissions(): array
	{
		return array_filter([
			'tcms-data' => is_writable($this->config->datadir),
			// Don't check cache if it's disabled
			'cache' => $this->getCacheWritable(),
			'logs'  => is_writable($this->config->logger['path']),
			'tmp'   => is_writable($this->config->tmpdir),
		]);
	}

	public function bundleCheck(): bool
	{
		try {
			$this->bundleChecker->check();
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}

	public function getVersion(): string
	{
		$version = false;
		$file    = __DIR__ . '/../../../version.txt';

		if (file_exists($file)) {
			$version = file_get_contents($file);
		}

		return $version ? trim($version) : 'Unknown';
	}
}

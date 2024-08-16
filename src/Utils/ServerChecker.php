<?php

namespace TotalCMS\Utils;

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
		// 'memcached',
		// 'opcache',
	];
	private const PHP_VERSION = '8.2.0';

	public function __construct(
		private BundleChecker $bundleChecker,
		private Config $config,
	) {}

	/**
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @return array<string,string>
	 * */
	public function serverInfo(): array
	{
		// Get the server information
		return [
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
			'Locale'             => setlocale(LC_ALL, ''),
		];
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

		return $software;
	}

	/** @return array<string,bool> */
	public function checkOptionalSoftware(): array
	{
		$software = [];
		foreach (self::OPTIONAL_SOFTWARE as $optionalSoftware) {
			$software["PHP Extension: $optionalSoftware"] = extension_loaded($optionalSoftware);
		}

		return $software;
	}

	/** @return array<string,bool> */
	public function checkPermissions(): array
	{
		return array_filter([
			'tcms-data' => is_writable($this->config->datadir),
			// Don't check cache if it's disabled
			'cache' => $this->config->cachedir !== "false" ? is_writable($this->config->cachedir) : null,
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
		$file    = __DIR__ . '/../../version';

		if (file_exists($file)) {
			$version = file_get_contents($file);
		}

		return $version ? trim($version) : 'Unknown';
	}
}

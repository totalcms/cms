<?php

namespace TotalCMS\Utils;

use TotalCMS\Domain\Bundle\Service\BundleChecker;

/**
 * Run tests against the system to
 */
class ServerChecker
{
	private const PHP_VERSION = '8.2.0';
	private const REQUIRED_SOFTWARE = [
		'gd',
		'curl',
		'exif_read_data',
		'mb_detect_encoding',
		'mbstring',
	];
	private const OPTIONAL_SOFTWARE = [
		'imagick',
	];

	public function __construct(
		private BundleChecker $bundleChecker,
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
			'OS'                 => PHP_OS,
			"Web Server"         => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
			"Domain"             => $_SERVER['SERVER_NAME'] ?? 'Unknown',
			"Document Root"      => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
			"Max POST Size"      => ini_get('post_max_size'),
			"Max Upload Size"    => ini_get('upload_max_filesize'),
			"Max Execution Time" => ini_get('max_execution_time'),
			"Memory Limit"       => ini_get('memory_limit'),
			"Timezone"           => date_default_timezone_get(),
			"Locale"             => setlocale(LC_ALL, ''),
		];
	}

	public function checkInstallation(): bool
	{
		return $this->bundleCheck();
	}

	/** @return array<string,bool> */
	public function checkRequiredSoftware(): array
	{
		$software = [
			"PHP {self::PHP_VERSION}+" => version_compare(PHP_VERSION, self::PHP_VERSION, '>='),
		];
		foreach (self::REQUIRED_SOFTWARE as $requiredSoftware) {
			$software[$requiredSoftware] = extension_loaded($requiredSoftware);
		}
		return $software;
	}

	/** @return array<string,bool> */
	public function checkOptionalSoftware(): array
	{
		$software = [];
		foreach (self::OPTIONAL_SOFTWARE as $optionalSoftware) {
			$software[$optionalSoftware] = extension_loaded($optionalSoftware);
		}
		return $software;
	}

	/** @return array<string,bool> */
	public function checkPermissions(): array
	{
		// tcms-data, cache, logs
		// get paths from Config?
		return [
			'tcms-data' => is_writable(__DIR__ . '/../../tcms-data'),
			'cache'     => is_writable(__DIR__ . '/../../cache'),
			'logs'      => is_writable(__DIR__ . '/../../logs'),
		];
	}

	private function bundleCheck(): bool
	{
		try {
			$this->bundleChecker->check();
		} catch (\Exception $e) {
			return false;
		}
		return true;
	}

	private function getVersion(): string
	{
		$version = file_get_contents(__DIR__ . '/../../version');
		return $version ?: 'Unknown';
	}
}

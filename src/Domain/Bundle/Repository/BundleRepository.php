<?php

namespace TotalCMS\Domain\Bundle\Repository;

use TotalCMS\Domain\Bundle\Data\BundleData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

class BundleRepository extends StorageRepository
{
	public static function resourcesDir(): string
	{
		return PathResolver::packageRoot() . '/resources/';
	}

	public static function bundlePath(): string
	{
		return self::resourcesDir() . 'bundle';
	}
	public const VALIDITY  = 60 * 60;

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly Config $config,
	) {
		parent::__construct($filesystem);
	}

	private function getLocalBundlePath(): string
	{
		return $this->config->datadir . '/.system/.bundle';
	}

	public function saveLocalBundle(BundleData $bundle): bool
	{
		$localBundlePath = $this->getLocalBundlePath();
		$dir             = dirname($localBundlePath);

		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($localBundlePath, base64_encode($bundle->toJSON()));

		if (!$this->localBundleExists()) {
			throw new \RuntimeException('Unable to save local bundle.');
		}

		return true;
	}

	public function bundleExists(): bool
	{
		return file_exists(self::bundlePath());
	}

	public function fetchBundle(): BundleData
	{
		if (!$this->bundleExists()) {
			throw new \RuntimeException('Bundle does not exist.');
		}

		$content = file_get_contents(self::bundlePath());

		if ($content === false) {
			throw new \RuntimeException('Error reading Bundle.');
		}

		$bundle = json_decode(base64_decode($content), true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException('Error decoding Bundle:' . json_last_error_msg());
		}

		return BundleData::fromArray($bundle);
	}

	public function localBundleExists(): bool
	{
		$this->localValidity();

		return file_exists($this->getLocalBundlePath());
	}

	private function localValidity(): void
	{
		$localBundlePath = $this->getLocalBundlePath();

		if (!file_exists($localBundlePath)) {
			return;
		}

		$time = time() - filemtime($localBundlePath);
		if ($time > self::VALIDITY) {
			unlink($localBundlePath);
		}
	}
}

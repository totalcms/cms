<?php

namespace TotalCMS\Domain\Bundle\Repository;

use TotalCMS\Domain\Bundle\Data\BundleData;
use TotalCMS\Domain\Storage\StorageRepository;

class BundleRepository extends StorageRepository
{
	public const RESOURCES   = __DIR__ . '/../../../../resources/';
	public const BUNDLE      = self::RESOURCES . 'bundle';
	public const LOCALBUNDLE = self::RESOURCES . '.bundle';
	public const VALIDITY    = 60 * 60;

	public function saveLocalBundle(BundleData $bundle): bool
	{
		file_put_contents(self::LOCALBUNDLE, base64_encode($bundle->toJSON()));

		if (!$this->localBundleExists()) {
			throw new \RuntimeException('Unable to save local bundle.');
		}

		return true;
	}

	public function bundleExists(): bool
	{
		return file_exists(self::BUNDLE);
	}

	public function fetchBundle(): BundleData
	{
		if (!$this->bundleExists()) {
			throw new \RuntimeException('Bundle does not exist.');
		}

		$content = file_get_contents(self::BUNDLE);

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

		return file_exists(self::LOCALBUNDLE);
	}

	private function localValidity(): void
	{
		if (!file_exists(self::LOCALBUNDLE)) {
			return;
		}

		$time = time() - filemtime(self::LOCALBUNDLE);
		if ($time > self::VALIDITY) {
			unlink(self::LOCALBUNDLE);
		}
	}
}

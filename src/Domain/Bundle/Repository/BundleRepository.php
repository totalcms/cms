<?php

namespace TotalCMS\Domain\Bundle\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Domain\Bundle\Data\BundleData;

final class BundleRepository extends StorageRepository
{
	const BUNDLE      = __DIR__ . '../../resources/bundle';
	const LOCALBUNDLE = __DIR__ . '../../resources/.bundle';
	const VALIDITY    = 3600;

	public function saveLocalBundle(BundleData $bundle): bool
	{
		return file_put_contents(self::LOCALBUNDLE, base64_encode($bundle)) !== false;
	}

	public function bundleExists(): bool
	{
		return file_exists(self::BUNDLE);
	}

	public function localBundleExists(): bool
	{
		$this->localValidity();
		return file_exists(self::LOCALBUNDLE);
	}

	private function localValidity(): void
	{
		$time = time() - filemtime(self::LOCALBUNDLE);
		if ($time > self::VALIDITY) {
			unlink(self::LOCALBUNDLE);
		}
	}
}

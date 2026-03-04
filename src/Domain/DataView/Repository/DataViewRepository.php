<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Repository;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;

class DataViewRepository extends StorageRepository
{
	private const BASE_PATH     = '.system/dataviews';
	private const CACHE_PREFIX  = 'dataview:';
	private const CACHE_TTL     = 14400;

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly CacheManager $cacheManager,
	) {
		parent::__construct($filesystem);
	}

	/** @param array<mixed> $data */
	public function saveData(string $viewId, array $data): void
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		$this->filesystem->write(self::BASE_PATH . "/{$viewId}/data.json", $json);
		$this->cacheManager->clearComputedData(self::CACHE_PREFIX . $viewId);
	}

	/**
	 * Fetch computed view data with cache-first strategy.
	 *
	 * @return array<mixed>
	 */
	public function fetchData(string $viewId): array
	{
		$cached = $this->cacheManager->getComputedData(self::CACHE_PREFIX . $viewId);
		if (is_array($cached)) {
			return $cached;
		}

		$path = self::BASE_PATH . "/{$viewId}/data.json";
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}

		$json = $this->filesystem->read($path);
		if ($json === '') {
			return [];
		}

		$data = json_decode($json, true);
		$data = is_array($data) ? $data : [];

		$this->cacheManager->storeComputedData(self::CACHE_PREFIX . $viewId, $data, self::CACHE_TTL);

		return $data;
	}

	public function dataExists(string $viewId): bool
	{
		return $this->filesystem->fileExists(self::BASE_PATH . "/{$viewId}/data.json");
	}

	public function deleteData(string $viewId): void
	{
		$dir = self::BASE_PATH . "/{$viewId}";
		if (!$this->filesystem->directoryExists($dir)) {
			return;
		}

		$this->filesystem->deleteDirectory($dir);
		$this->cacheManager->clearComputedData(self::CACHE_PREFIX . $viewId);
	}
}

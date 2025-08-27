<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Support\Config;

/**
 * Service for managing ImageWorks cache files.
 * Handles clearing and analyzing .cache directories within collections.
 */
readonly class ImageCacheService
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Clear all image cache files for a specific collection.
	 * This clears all .cache directories within the collection folder.
	 *
	 * @throws \RuntimeException if collection doesn't exist or cache clearing fails
	 */
	public function clearCollectionImageCache(string $collection): bool
	{
		// Get the collection path from config
		$collectionPath = $this->config->datadir . '/' . $collection;

		if (!is_dir($collectionPath)) {
			throw new \RuntimeException("Collection directory does not exist: {$collectionPath}");
		}

		// First, collect all .cache directories to avoid iterator issues during deletion
		$cacheDirectories = [];

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($collectionPath, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($iterator as $file) {
				if ($file->isDir() && $file->getFilename() === '.cache') {
					$cacheDirectories[] = $file->getPathname();
				}
			}
		} catch (\Exception $e) {
			throw new \RuntimeException('Failed to scan collection directory: ' . $e->getMessage(), $e->getCode(), $e);
		}

		// Now remove all found cache directories
		$cachesCleared = 0;
		foreach ($cacheDirectories as $cachePath) {
			if (is_dir($cachePath)) {
				$this->removeDirectory($cachePath);
				$cachesCleared++;
			}
		}

		return true;
	}

	/**
	 * Get image cache statistics for a collection.
	 *
	 * @return array<string,mixed>
	 */
	public function getCollectionImageCacheStats(string $collection): array
	{
		$collectionPath = $this->config->datadir . '/' . $collection;

		$stats = [
			'collection'        => $collection,
			'cache_directories' => 0,
			'cached_files'      => 0,
			'total_size_bytes'  => 0,
			'exists'            => false,
		];

		if (!is_dir($collectionPath)) {
			return $stats;
		}

		$stats['exists'] = true;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($collectionPath, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($iterator as $file) {
				if ($file->isDir() && $file->getFilename() === '.cache') {
					$stats['cache_directories']++;

					// Count files and size in this cache directory
					$cacheIterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator($file->getPathname(), \RecursiveDirectoryIterator::SKIP_DOTS)
					);

					foreach ($cacheIterator as $cacheFile) {
						if ($cacheFile->isFile()) {
							$stats['cached_files']++;
							$stats['total_size_bytes'] += $cacheFile->getSize();
						}
					}
				}
			}
		} catch (\Exception) {
			// If we can't read the directory, just return basic stats
			// This prevents errors from breaking the stats collection
		}

		$stats['total_size_mb'] = round($stats['total_size_bytes'] / 1024 / 1024, 2);

		return $stats;
	}

	/**
	 * Get image cache statistics for all collections.
	 *
	 * @return array<array<string,mixed>> Array of collection cache statistics
	 */
	public function getAllCollectionImageCacheStats(): array
	{
		$datadir = $this->config->datadir;
		$results = [];

		if (!is_dir($datadir)) {
			return $results;
		}

		// Get all collection directories
		$collections = array_filter(
			scandir($datadir) ?: [],
			fn (string $item): bool => $item !== '.' && $item !== '..' && is_dir($datadir . '/' . $item)
		);

		foreach ($collections as $collection) {
			$stats = $this->getCollectionImageCacheStats($collection);
			// Only include collections that have cached files
			if ($stats['exists'] && $stats['cached_files'] > 0) {
				$results[] = $stats;
			}
		}

		// Sort by collection name
		usort($results, fn (array $a, array $b): int => strcmp((string)$a['collection'], (string)$b['collection']));

		return $results;
	}

	/**
	 * Clear image cache for all collections.
	 *
	 * @return array<string,mixed> Summary of clearing operation
	 */
	public function clearAllCollectionImageCaches(): array
	{
		$datadir = $this->config->datadir;
		$results = [
			'collections_processed'     => 0,
			'cache_directories_cleared' => 0,
			'errors'                    => [],
		];

		if (!is_dir($datadir)) {
			throw new \RuntimeException("Data directory does not exist: {$datadir}");
		}

		// Get all collection directories
		$collections = array_filter(
			scandir($datadir) ?: [],
			fn (string $item): bool => $item !== '.' && $item !== '..' && is_dir($datadir . '/' . $item)
		);

		foreach ($collections as $collection) {
			try {
				$this->clearCollectionImageCache($collection);
				$results['collections_processed']++;
			} catch (\RuntimeException $e) {
				$results['errors'][] = "Failed to clear cache for collection '{$collection}': " . $e->getMessage();
			}
		}

		return $results;
	}

	/**
	 * Recursively remove a directory and all its contents.
	 *
	 * @throws \RuntimeException if directory removal fails
	 */
	private function removeDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			$realPath = $file->getRealPath();
			if ($file->isDir()) {
				if (!rmdir($realPath)) {
					throw new \RuntimeException("Failed to remove directory: {$realPath}");
				}
			} elseif (!unlink($realPath)) {
				throw new \RuntimeException("Failed to remove file: {$realPath}");
			}
		}

		if (!rmdir($path)) {
			throw new \RuntimeException("Failed to remove root directory: {$path}");
		}
	}
}

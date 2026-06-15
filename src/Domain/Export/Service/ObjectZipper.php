<?php

namespace TotalCMS\Domain\Export\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Service for creating zip files of a single object's data and assets.
 */
readonly class ObjectZipper
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Create a zip file of an object's JSON file and assets folder.
	 *
	 * @param string $collection The collection name
	 * @param string $id         The object ID
	 *
	 * @throws \RuntimeException If zip creation fails or object not found
	 *
	 * @return string The path to the created zip file
	 */
	public function createObjectZip(string $collection, string $id): string
	{
		$datadir = $this->config->datadir;

		// Paths
		$objectFile = PathUtils::buildPath(collection: $collection, filename: $id . '.json');
		$assetsPath = PathUtils::buildPath(collection: $collection, filename: $id);

		$fullObjectPath = PathUtils::absolutePath($datadir, $objectFile);

		// Verify object exists
		if (!file_exists($fullObjectPath)) {
			throw new \RuntimeException("Object not found: {$collection}/{$id}");
		}

		// Create temp zip
		$tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
			'object-' . $collection . '-' . $id . '-' . time() . '.zip';

		$zip    = new \ZipArchive();
		$result = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		if ($result !== true) {
			throw new \RuntimeException(sprintf('Failed to create zip file: %s (Error code: %d)', $tempZipPath, $result));
		}

		// Add JSON file
		$zip->addFile($fullObjectPath, $id . '.json');

		// Add assets folder if exists and has non-cache contents
		$fullAssetsPath = PathUtils::absolutePath($datadir, $assetsPath);
		if (is_dir($fullAssetsPath) && $this->hasNonCacheContents($fullAssetsPath)) {
			$this->addDirectoryToZip($zip, $fullAssetsPath, $id);
		}

		$zip->close();

		return $tempZipPath;
	}

	/**
	 * Create a zip of several objects' JSON files and asset folders. Each object
	 * lands at the zip root as `{id}.json` plus its `{id}/` assets folder, the
	 * same layout createObjectZip() produces for a single object. Ids that no
	 * longer exist are skipped.
	 *
	 * @param array<int,string> $ids
	 *
	 * @throws \RuntimeException If zip creation fails or no ids resolve to objects
	 *
	 * @return string The path to the created zip file
	 */
	public function createObjectsZip(string $collection, array $ids): string
	{
		$datadir = $this->config->datadir;

		$tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
			'objects-' . $collection . '-' . time() . '.zip';

		$zip    = new \ZipArchive();
		$result = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		if ($result !== true) {
			throw new \RuntimeException(sprintf('Failed to create zip file: %s (Error code: %d)', $tempZipPath, $result));
		}

		$added = 0;
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id === '') {
				continue;
			}

			$objectFile     = PathUtils::buildPath(collection: $collection, filename: $id . '.json');
			$fullObjectPath = PathUtils::absolutePath($datadir, $objectFile);
			if (!file_exists($fullObjectPath)) {
				continue; // skip missing ids and move on
			}

			$zip->addFile($fullObjectPath, $id . '.json');

			$assetsPath     = PathUtils::buildPath(collection: $collection, filename: $id);
			$fullAssetsPath = PathUtils::absolutePath($datadir, $assetsPath);
			if (is_dir($fullAssetsPath) && $this->hasNonCacheContents($fullAssetsPath)) {
				$this->addDirectoryToZip($zip, $fullAssetsPath, $id);
			}

			$added++;
		}

		if ($added === 0) {
			$zip->close();
			// ZipArchive doesn't write a file to disk when no entries were added,
			// so only unlink if it actually exists.
			if (file_exists($tempZipPath)) {
				unlink($tempZipPath);
			}
			throw new \RuntimeException("No objects found to export in collection: {$collection}");
		}

		$zip->close();

		return $tempZipPath;
	}

	/**
	 * Check if a directory has any contents that are not .cache directories.
	 */
	private function hasNonCacheContents(string $path): bool
	{
		$iterator = new \DirectoryIterator($path);
		foreach ($iterator as $file) {
			if ($file->isDot()) {
				continue;
			}
			// Skip .cache directories
			if ($file->getFilename() === '.cache') {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Recursively add directory contents to zip, excluding .cache folders.
	 */
	private function addDirectoryToZip(\ZipArchive $zip, string $realPath, string $zipPath): void
	{
		// Resolve to canonical path to match getRealPath() results
		$canonicalPath = realpath($realPath);
		if ($canonicalPath === false) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($canonicalPath, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			$filePath     = $file->getRealPath();
			$relativePath = substr((string)$filePath, strlen($canonicalPath) + 1);

			// Skip .cache directories and their contents
			if (str_contains($relativePath, '.cache')) {
				continue;
			}

			$zipFilePath = $zipPath . DIRECTORY_SEPARATOR . $relativePath;

			if ($file->isDir()) {
				$zip->addEmptyDir($zipFilePath);
			} elseif ($file->isFile()) {
				$zip->addFile($filePath, $zipFilePath);
			}
		}
	}

	/**
	 * Get the filename for the zip download.
	 */
	public function getZipFilename(string $collection, string $id): string
	{
		return sprintf('%s--%s.zip', $collection, $id);
	}

	/**
	 * Get the filename for a multi-object zip download.
	 */
	public function getObjectsZipFilename(string $collection): string
	{
		return sprintf('%s-objects.zip', $collection);
	}
}

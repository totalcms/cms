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

		$fullObjectPath = $datadir . DIRECTORY_SEPARATOR . $objectFile;

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
		$fullAssetsPath = $datadir . DIRECTORY_SEPARATOR . $assetsPath;
		if (is_dir($fullAssetsPath) && $this->hasNonCacheContents($fullAssetsPath)) {
			$this->addDirectoryToZip($zip, $fullAssetsPath, $id);
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
}

<?php

namespace TotalCMS\Domain\Export\Service;

use TotalCMS\Support\Config;

/**
 * Service for creating zip files of collection data including all files and folders.
 */
readonly class CollectionZipper
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Create a zip file of a collection's data folder.
	 *
	 * @param string $collection The collection name
	 *
	 * @throws \RuntimeException If zip creation fails
	 *
	 * @return string The path to the created zip file
	 */
	public function createCollectionZip(string $collection): string
	{
		$collectionPath = $this->config->datadir . DIRECTORY_SEPARATOR . $collection;

		if (!is_dir($collectionPath)) {
			throw new \RuntimeException(sprintf('Collection directory not found: %s', $collectionPath));
		}

		// Create temporary zip file
		$tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'collection-' . $collection . '-' . time() . '.zip';

		$zip    = new \ZipArchive();
		$result = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		if ($result !== true) {
			throw new \RuntimeException(sprintf('Failed to create zip file: %s (Error code: %d)', $tempZipPath, $result));
		}

		$this->addDirectoryToZip($zip, $collectionPath, $collection);

		$zip->close();

		return $tempZipPath;
	}

	/**
	 * Recursively add directory contents to zip, excluding .cache folders.
	 */
	private function addDirectoryToZip(\ZipArchive $zip, string $realPath, string $zipPath): void
	{
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($realPath, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			$filePath     = $file->getRealPath();
			$relativePath = substr((string)$filePath, strlen($realPath) + 1);

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
	public function getZipFilename(string $collection): string
	{
		return sprintf('collection-%s.zip', $collection);
	}
}

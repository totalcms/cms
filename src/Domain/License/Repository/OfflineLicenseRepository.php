<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Repository;

use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * Repository for offline license file operations.
 *
 * Handles storage of offline license files in the tcms-data directory.
 * Users upload files to tcms-data/{domain}-offline-license.key
 * Files are automatically moved to tcms-data/.system/{domain}-offline-license.key
 */
readonly class OfflineLicenseRepository
{
	private const SYSTEM_DIR     = '.system';
	private const LICENSE_SUFFIX = '-offline-license.key';

	public function __construct(
		private StorageAdapterInterface $storage,
		private Config $config,
	) {
	}

	/**
	 * Check if an offline license file exists (in either location).
	 */
	public function exists(): bool
	{
		// First check and move from upload location if needed
		$this->moveFromUploadLocation();

		return $this->storage->fileExists($this->getSystemPath());
	}

	/**
	 * Read the offline license token.
	 * Returns null if file doesn't exist or is empty.
	 */
	public function read(): ?string
	{
		// First check and move from upload location if needed
		$this->moveFromUploadLocation();

		$path = $this->getSystemPath();

		if (!$this->storage->fileExists($path)) {
			return null;
		}

		$content = $this->storage->read($path);

		if (trim($content) === '') {
			return null;
		}

		return trim($content);
	}

	/**
	 * Delete the offline license file.
	 */
	public function delete(): bool
	{
		$path = $this->getSystemPath();

		if (!$this->storage->fileExists($path)) {
			return true;
		}

		return $this->storage->delete($path);
	}

	/**
	 * Get the expected filename for the offline license.
	 * e.g., "example.com-offline-license.key".
	 */
	public function getExpectedFilename(): string
	{
		return $this->config->domain . self::LICENSE_SUFFIX;
	}

	/**
	 * Get the upload directory (where users place files).
	 * This is the root of tcms-data.
	 */
	public function getUploadDirectory(): string
	{
		return '';
	}

	/**
	 * Check for license file in upload location and move to .system if found.
	 * This allows users to simply drop the file in tcms-data/ via FTP/SSH.
	 */
	private function moveFromUploadLocation(): void
	{
		$uploadPath = $this->getUploadPath();
		$systemPath = $this->getSystemPath();

		if (!$this->storage->fileExists($uploadPath)) {
			return;
		}

		// Ensure .system directory exists
		if (!$this->storage->directoryExists(self::SYSTEM_DIR)) {
			// StorageAdapter will create directories as needed during write/move
		}

		// Move the file to .system directory
		$this->storage->move($uploadPath, $systemPath);
	}

	/**
	 * Get the upload location path (where users drop the file).
	 * Location: {domain}-offline-license.key (root of tcms-data).
	 */
	private function getUploadPath(): string
	{
		return $this->config->domain . self::LICENSE_SUFFIX;
	}

	/**
	 * Get the system location path (secure storage).
	 * Location: .system/{domain}-offline-license.key.
	 */
	private function getSystemPath(): string
	{
		return self::SYSTEM_DIR . '/' . $this->config->domain . self::LICENSE_SUFFIX;
	}
}

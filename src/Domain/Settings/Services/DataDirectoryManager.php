<?php

namespace TotalCMS\Domain\Settings\Services;

/**
 * Manages data directory creation, validation, and cleanup.
 */
readonly class DataDirectoryManager
{
	/**
	 * Create data directory with security files.
	 *
	 * @throws \RuntimeException If directory creation fails
	 */
	public function createDirectory(string $path): void
	{
		if (!mkdir($path, 0755, true)) {
			throw new \RuntimeException('Failed to create directory: ' . $path);
		}

		$this->createSecurityFile($path);
	}

	/**
	 * Create .htaccess file to deny direct web access.
	 */
	private function createSecurityFile(string $path): void
	{
		$htaccessPath    = $path . '/.htaccess';
		$htaccessContent = <<<'HTACCESS'
# Deny direct access to all files and folders in tcms-data
# This protects sensitive data including API keys, collections, and user data

<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>
HTACCESS;
		@file_put_contents($htaccessPath, $htaccessContent);
	}

	/**
	 * Validate that a path is an absolute path.
	 *
	 * @throws \InvalidArgumentException If path is not absolute
	 */
	public function validateAbsolutePath(string $path): void
	{
		if (!str_starts_with($path, '/')) {
			throw new \InvalidArgumentException('Custom path must be an absolute path (starting with /).');
		}
	}

	/**
	 * Validate that parent directory exists and is writable.
	 *
	 * @throws \RuntimeException If parent directory is invalid
	 */
	public function validateParentDirectory(string $path): void
	{
		$parentDir = dirname($path);

		if (!is_dir($parentDir)) {
			throw new \RuntimeException('Parent directory does not exist: ' . $parentDir);
		}

		if (!is_writable($parentDir)) {
			throw new \RuntimeException('Parent directory is not writable: ' . $parentDir);
		}
	}

	/**
	 * Validate that path is a writable directory.
	 *
	 * @throws \RuntimeException If path is not a valid writable directory
	 */
	public function validateDirectory(string $path): void
	{
		if (!is_dir($path)) {
			throw new \RuntimeException('Path exists but is not a directory: ' . $path);
		}

		if (!is_writable($path)) {
			throw new \RuntimeException('Directory is not writable: ' . $path);
		}
	}

	/**
	 * Check if directory is empty (only contains . and ..).
	 */
	public function isDirectoryEmpty(string $path): bool
	{
		if (!is_dir($path)) {
			return true;
		}

		$files = @scandir($path);

		return $files !== false && count($files) === 2;
	}

	/**
	 * Clean up empty default directory if user chose a different path.
	 */
	public function cleanupEmptyDefaultDirectory(string $defaultPath, string $chosenPath): void
	{
		// Don't delete if paths are the same
		if ($defaultPath === $chosenPath) {
			return;
		}

		// Don't delete if directory doesn't exist
		if (!is_dir($defaultPath)) {
			return;
		}

		// Only delete if empty
		if ($this->isDirectoryEmpty($defaultPath)) {
			@rmdir($defaultPath);
		}
	}

	/**
	 * Determine data path based on location choice.
	 *
	 * @param string $location Location choice: 'default', 'docroot', or 'custom'
	 * @param string $docroot Document root path
	 * @param string $customPath Custom path (used when location is 'custom')
	 *
	 * @return string Resolved data path
	 */
	public function resolveDataPath(string $location, string $docroot, string $customPath = ''): string
	{
		return match ($location) {
			'default' => dirname($docroot) . '/tcms-data',
			'docroot' => $docroot . '/tcms-data',
			'custom'  => trim($customPath),
			default   => '',
		};
	}
}

<?php

declare(strict_types=1);

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
	 * Migrate an auto-created tcms-data directory to the user's chosen
	 * location. Preserves any bootstrap state that was written during the
	 * wizard flow (extension discovery, etc.) — preferable to deleting it
	 * and having every consumer rebuild on the next request.
	 *
	 * Returns true on a successful move, false otherwise. Caller is
	 * responsible for falling back to createDirectory() if no candidate
	 * source could be moved.
	 *
	 * Skipped (returns false) when:
	 *   - $from doesn't exist
	 *   - $from === $to
	 *   - $to exists and contains real user data (a top-level entry other
	 *     than `.htaccess` or `.system/` — preserves that data, caller
	 *     should bail or warn)
	 *   - The parent of $to doesn't exist (validation should catch this
	 *     earlier; defensive check here)
	 *   - The underlying rename() fails (cross-device, permissions, etc.)
	 *
	 * If $to exists and contains only bootstrap junk, that junk is removed
	 * before the rename so the rename has a clean destination.
	 */
	public function moveDataDirectory(string $from, string $to): bool
	{
		if ($from === $to) {
			return false;
		}

		if (!is_dir($from)) {
			return false;
		}

		if (is_dir($to)) {
			if ($this->containsUserData($to)) {
				return false;
			}
			$this->removeBootstrapDirectory($to);
		}

		$parent = dirname($to);
		if (!is_dir($parent)) {
			return false;
		}

		return @rename($from, $to);
	}

	/**
	 * True if the directory contains anything beyond auto-generated
	 * bootstrap junk (the security `.htaccess` and `.system/`).
	 */
	private function containsUserData(string $path): bool
	{
		$entries = @scandir($path);
		if ($entries === false) {
			return true;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if ($entry === '.htaccess' || $entry === '.system') {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Recursively remove a bootstrap-only data directory. Caller must have
	 * already verified via containsUserData() that no real content lives
	 * here — this helper does no further checks.
	 */
	private function removeBootstrapDirectory(string $path): void
	{
		$entries = @scandir($path);
		if ($entries === false) {
			return;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;
			if (is_dir($child) && !is_link($child)) {
				$this->removeBootstrapDirectory($child);
			} else {
				@unlink($child);
			}
		}

		@rmdir($path);
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

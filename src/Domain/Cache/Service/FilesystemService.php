<?php

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Filesystem cache service.
 */
readonly class FilesystemService implements CacheInterface
{
	private bool $enabled;
	private string $cacheDir;

	/**
	 * The project cache directory. Same as `cacheDir` in normal operation; in
	 * shared mode `cacheDir` moves into tcms-data and this still points at the
	 * project directory, which Twig uses for compiled templates.
	 */
	private string $localDir;

	public function __construct(
		Config $config,
	) {
		$this->enabled  = $config->cache['filesystem'] ?? true;
		$this->localDir = $config->cachedir;

		// Installs sharing one tcms-data share the disk layer too. Without this
		// a shared key namespace still lets each install answer from its own
		// stale file, because reads fall through to the filesystem last.
		$domainScoped   = ($config->cache['domainScoped'] ?? true) === true;
		$this->cacheDir = $domainScoped
			? $config->cachedir
			: PathUtils::absolutePath($config->systemDir(), 'cache');

		$this->createCacheDir();
	}

	public function isAvailable(): bool
	{
		if (!$this->enabled) {
			return false;
		}

		return $this->isWritable();
	}

	public function isInstalled(): bool
	{
		// Filesystem is always available in PHP
		return true;
	}

	public function isActive(): bool
	{
		return $this->enabled && $this->isAvailable();
	}

	/**
	 * Whether the cache directory exists and is writable, irrespective of
	 * the `cache.filesystem` enabled flag. Used by mandatory cache paths
	 * (e.g. license caching) that must continue working even when the user
	 * has explicitly disabled filesystem caching.
	 */
	private function isWritable(): bool
	{
		if ($this->cacheDir === '') {
			return false;
		}

		return $this->createCacheDir();
	}

	private function createCacheDir(): bool
	{
		if ($this->cacheDir === '') {
			return false;
		}

		// Try to create cache directory if it doesn't exist. The `@` swallows
		// the "File exists" warning when a concurrent request wins the race
		// between this check and mkdir() — the trailing is_dir() re-check is
		// the real success test, so the race is benign and must stay silent.
		if (!is_dir($this->cacheDir)) {
			try {
				if (!@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
					return false;
				}
			} catch (\Exception) {
				return false;
			}
		}

		return is_writable($this->cacheDir);
	}

	public function getCachDir(): string
	{
		return $this->cacheDir;
	}

	/**
	 * The project cache directory, which never moves into tcms-data.
	 *
	 * Callers storing state that describes THIS INSTALL rather than the shared
	 * content — the running T3 version, compiled artifacts — must anchor to this
	 * instead of {@see getCachDir()}. In shared mode the entry directory is
	 * common to every install on the data folder, so install-specific state
	 * written there would be fought over.
	 */
	public function getLocalDir(): string
	{
		return $this->localDir;
	}

	public function get(string $key): mixed
	{
		if (!$this->isAvailable()) {
			return null;
		}

		return $this->readEntry($key);
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		return $this->writeEntry($key, $value, $ttl);
	}

	/**
	 * Read a cache entry while bypassing the `cache.filesystem` enabled flag.
	 *
	 * Used for mandatory cache paths (license validation) that must keep
	 * working even when filesystem caching is disabled — without this,
	 * disabling all cache backends in dev would cause license calls to hit
	 * the API on every request and trip the upstream rate limiter.
	 */
	public function getMandatory(string $key): mixed
	{
		if (!$this->isWritable()) {
			return null;
		}

		return $this->readEntry($key);
	}

	/**
	 * Counterpart to {@see getMandatory()} — write a cache entry while
	 * bypassing the `cache.filesystem` enabled flag.
	 */
	public function setMandatory(string $key, mixed $value, int $ttl = 0): bool
	{
		if (!$this->isWritable()) {
			return false;
		}

		return $this->writeEntry($key, $value, $ttl);
	}

	private function readEntry(string $key): mixed
	{
		$filePath = $this->getFilePath($key);
		if (!file_exists($filePath)) {
			return null;
		}

		try {
			$content = file_get_contents($filePath);
			if ($content === false) {
				return null;
			}
		} catch (\Exception) {
			return null;
		}

		$data = unserialize($content, ['allowed_classes' => false]);
		if (!is_array($data) || !isset($data['expires'], $data['value'])) {
			return null;
		}

		// Check if expired
		if ($data['expires'] > 0 && time() > $data['expires']) {
			try {
				unlink($filePath);
			} catch (\Exception) {
				// Ignore unlink failures for expired files
			}

			return null;
		}

		return $data['value'];
	}

	private function writeEntry(string $key, mixed $value, int $ttl): bool
	{
		$filePath = $this->getFilePath($key);
		$expires  = $ttl > 0 ? time() + $ttl : 0;

		$data = [
			'key'     => $key,
			'expires' => $expires,
			'value'   => $value,
		];

		try {
			return file_put_contents($filePath, serialize($data), LOCK_EX) !== false;
		} catch (\Exception) {
			return false;
		}
	}

	public function delete(string $key): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		return $this->deleteEntry($key);
	}

	/**
	 * Counterpart to {@see getMandatory()} / {@see setMandatory()} — delete a
	 * cache entry while bypassing the `cache.filesystem` enabled flag.
	 * Without this, clearing license data while filesystem caching is
	 * disabled would silently fail and leave a stale entry on disk.
	 */
	public function deleteMandatory(string $key): bool
	{
		if (!$this->isWritable()) {
			return false;
		}

		return $this->deleteEntry($key);
	}

	private function deleteEntry(string $key): bool
	{
		$filePath = $this->getFilePath($key);
		if (!file_exists($filePath)) {
			return true;
		}

		try {
			return unlink($filePath);
		} catch (\Exception) {
			return false;
		}
	}

	public function clear(): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		$cleared = $this->deleteDirectory($this->cacheDir, true);

		// In shared mode the entry directory lives in tcms-data, but Twig still
		// compiles templates into the project cache directory with auto_reload
		// off in production. Clearing only the entry directory would leave
		// compiled templates stale and edits to shared builder templates would
		// never go live.
		if ($this->localDir !== '' && $this->localDir !== $this->cacheDir) {
			$cleared = $this->deleteDirectory($this->localDir, true) && $cleared;
		}

		return $cleared;
	}

	/**
	 * Clear cache entries by pattern.
	 * Iterates cache files and deletes those whose stored key matches the pattern.
	 * Pattern uses * as a wildcard (e.g., "prefix:api:*").
	 */
	public function clearByPattern(string $pattern): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		try {
			// Convert glob-style pattern to regex
			$regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file->isFile() || $file->getExtension() !== 'cache') {
					continue;
				}

				// `@` swallows the warning when another request (or an expiring
				// read) unlinks the file between the iterator listing it and
				// this read — a benign race during a concurrent pattern clear.
				$content = @file_get_contents($file->getPathname());
				if ($content === false) {
					continue;
				}

				$data = unserialize($content, ['allowed_classes' => false]);
				if (!is_array($data) || !isset($data['key'])) {
					continue;
				}

				if (preg_match($regex, (string)$data['key'])) {
					unlink($file->getPathname());
				}
			}

			return true;
		} catch (\Exception) {
			return false;
		}
	}

	public function getStats(): array
	{
		if (!$this->isAvailable()) {
			return [
				'available' => false,
				'enabled'   => $this->enabled,
				'directory' => $this->cacheDir,
			];
		}

		$size  = 0;
		$files = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->cacheDir)
		);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$size += $file->getSize();
				$files++;
			}
		}

		return [
			'available' => true,
			'enabled'   => $this->enabled,
			'directory' => $this->cacheDir,
			'size'      => $size,
			'files'     => $files,
			'size_mb'   => round($size / 1024 / 1024, 2),
		];
	}

	public function getName(): string
	{
		return 'Filesystem';
	}

	public function getRecommendations(): array
	{
		if (!$this->isAvailable()) {
			return ['❌ Filesystem caching is disabled or directory not writable'];
		}

		return ['✅ Filesystem caching is available for template storage'];
	}

	private function getFilePath(string $key): string
	{
		$hash   = hash('sha256', $key);
		$subDir = substr($hash, 0, 2);
		$dir    = $this->cacheDir . '/' . $subDir;

		// `@` swallows the "File exists" warning from the check-then-mkdir race
		// when a concurrent request creates the same shard dir first (TOCTOU).
		// A genuine failure (e.g. permissions) is harmless here — the cache
		// write that follows fails gracefully in writeEntry().
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		return $dir . '/' . $hash . '.cache';
	}

	private function deleteDirectory(string $dir, bool $preserveRoot = false): bool
	{
		if (!file_exists($dir)) {
			return true;
		}

		if (!is_dir($dir)) {
			try {
				return unlink($dir);
			} catch (\Exception) {
				return false;
			}
		}

		try {
			$items = scandir($dir);
			if ($items === false) {
				return false;
			}
		} catch (\Exception) {
			return false;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (!$this->deleteDirectory($path)) {
				return false;
			}
		}

		if ($preserveRoot) {
			return true;
		}

		try {
			return rmdir($dir);
		} catch (\Exception) {
			return false;
		}
	}
}

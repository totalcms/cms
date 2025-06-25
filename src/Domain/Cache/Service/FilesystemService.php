<?php

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Support\Config;

/**
 * Filesystem cache service.
 */
final class FilesystemService implements CacheInterface
{
	private bool $enabled;
	private string $cacheDir;

	public function __construct(
		Config $config,
	) {
		$filesystemConfig = $config->cache['filesystem'] ?? [];
		$this->enabled    = $filesystemConfig['enabled'] ?? true;
		$this->cacheDir   = $filesystemConfig['directory'] ?? '';

		$this->createCacheDir();
	}

	public function isAvailable(): bool
	{
		if (!$this->enabled || empty($this->cacheDir)) {
			return false;
		}

		return $this->createCacheDir();
	}

	private function createCacheDir(): bool
	{
		if (empty($this->cacheDir)) {
			return false;
		}

		// Try to create cache directory if it doesn't exist
		if (!is_dir($this->cacheDir)) {
			try {
				if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
					return false;
				}
			} catch (\Exception $e) {
				return false;
			}
		}

		return is_writable($this->cacheDir);
	}

	public function getCachDir(): string
	{
		return $this->cacheDir;
	}

	public function get(string $key): mixed
	{
		if (!$this->isAvailable()) {
			return null;
		}

		$filePath = $this->getFilePath($key);
		if (!file_exists($filePath)) {
			return null;
		}

		try {
			$content = file_get_contents($filePath);
			if ($content === false) {
				return null;
			}
		} catch (\Exception $e) {
			return null;
		}

		$data = unserialize($content);
		if (!is_array($data) || !isset($data['expires'], $data['value'])) {
			return null;
		}

		// Check if expired
		if ($data['expires'] > 0 && time() > $data['expires']) {
			try {
				unlink($filePath);
			} catch (\Exception $e) {
				// Ignore unlink failures for expired files
			}

			return null;
		}

		return $data['value'];
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		$filePath = $this->getFilePath($key);
		$expires  = $ttl > 0 ? time() + $ttl : 0;

		$data = [
			'expires' => $expires,
			'value'   => $value,
		];

		try {
			return file_put_contents($filePath, serialize($data), LOCK_EX) !== false;
		} catch (\Exception $e) {
			return false;
		}
	}

	public function delete(string $key): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		$filePath = $this->getFilePath($key);
		if (!file_exists($filePath)) {
			return true;
		}

		try {
			return unlink($filePath);
		} catch (\Exception $e) {
			return false;
		}
	}

	public function clear(): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		return $this->deleteDirectory($this->cacheDir, true);
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

		if (!is_dir($dir)) {
			try {
				mkdir($dir, 0755, true);
			} catch (\Exception $e) {
				// Directory creation failed, will use parent dir
			}
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
			} catch (\Exception $e) {
				return false;
			}
		}

		try {
			$items = scandir($dir);
			if ($items === false) {
				return false;
			}
		} catch (\Exception $e) {
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
		} catch (\Exception $e) {
			return false;
		}
	}
}

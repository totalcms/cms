<?php

declare(strict_types=1);

namespace Tests\Fakes;

use League\Flysystem\FilesystemOperator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * A simple in-memory StorageAdapterInterface for unit tests — an associative
 * array of path => contents. More reliable than a mock with by-reference
 * callbacks for code that does repeated read-after-write.
 */
final class InMemoryFilesystem implements StorageAdapterInterface
{
	/** @var array<string,string> */
	public array $files = [];

	public function flysystem(): FilesystemOperator
	{
		throw new \RuntimeException('Not available in the in-memory fake.');
	}

	public function read(string $location): string
	{
		return $this->files[$location] ?? '';
	}

	public function readStream(string $location): null
	{
		return null;
	}

	public function fileExists(string $location): bool
	{
		return isset($this->files[$location]);
	}

	public function directoryExists(string $location): bool
	{
		foreach (array_keys($this->files) as $path) {
			if (str_starts_with($path, rtrim($location, '/') . '/')) {
				return true;
			}
		}

		return false;
	}

	public function mimeType(string $location): string
	{
		return 'text/plain';
	}

	public function fileSize(string $location): int
	{
		return strlen($this->files[$location] ?? '');
	}

	public function write(string $location, string $contents): bool
	{
		$this->files[$location] = $contents;

		return true;
	}

	public function import(string $import, string $dest): bool
	{
		return true;
	}

	public function move(string $old, string $new): bool
	{
		$this->files[$new] = $this->files[$old] ?? '';
		unset($this->files[$old]);

		return true;
	}

	public function copyDirectory(string $old, string $new): bool
	{
		return true;
	}

	public function delete(string $location): bool
	{
		unset($this->files[$location]);

		return true;
	}

	public function deleteDirectory(string $location): bool
	{
		$prefix = rtrim($location, '/') . '/';
		foreach (array_keys($this->files) as $path) {
			if (str_starts_with($path, $prefix)) {
				unset($this->files[$path]);
			}
		}

		return true;
	}

	/** @return array<int,string> */
	public function listDirectories(string $directory): array
	{
		$prefix = rtrim($directory, '/') . '/';
		$dirs   = [];
		foreach (array_keys($this->files) as $path) {
			if (str_starts_with($path, $prefix)) {
				$rest = substr($path, strlen($prefix));
				if (str_contains($rest, '/')) {
					$dirs[$prefix . explode('/', $rest)[0]] = true;
				}
			}
		}

		return array_keys($dirs);
	}

	/** @return array<int,string> */
	public function listFiles(string $directory): array
	{
		$prefix = rtrim($directory, '/') . '/';
		$out    = [];
		foreach (array_keys($this->files) as $path) {
			if (str_starts_with($path, $prefix) && !str_contains(substr($path, strlen($prefix)), '/')) {
				$out[] = $path;
			}
		}

		return $out;
	}
}

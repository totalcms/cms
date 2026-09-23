<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Absolute on-disk paths for stored files, for PHP code that needs to read
 * the bytes rather than link to them (`cms.filePath()` / `cms.depotPath()`).
 * Null when the file is not there.
 */
readonly class FilePathResolver
{
	public function __construct(
		private FileFetcher $fileFetcher,
		private Config $config,
	) {
	}

	/**
	 * The file behind a file property.
	 *
	 * @param array<string,string> $options `collection` (default `file`), `property` (default `file`)
	 */
	public function filePath(string $id, array $options = []): ?string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';

		try {
			$file = $this->fileFetcher->fetchFile($collection, $id, $property);
		} catch (\Throwable) {
			return null;
		}

		return $this->absolute(PathUtils::buildPath($collection, $id, $property, $file->name));
	}

	/**
	 * A file inside a depot. `$filePath` may carry folders (`docs/2026/a.pdf`).
	 *
	 * @param array<string,string> $options `collection` (default `depot`), `property` (default `depot`)
	 */
	public function depotPath(string $id, string $filePath, array $options = []): ?string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';

		$subpath  = '';
		$filename = $filePath;
		if (str_contains($filePath, '/')) {
			$pathinfo = pathinfo($filePath);
			$subpath  = $pathinfo['dirname'];
			$filename = $pathinfo['basename'];
		}

		try {
			$fullPath = $this->absolute(PathUtils::buildPath($collection, $id, $property, $filename, $subpath));
		} catch (\Throwable) {
			return null;
		}

		return file_exists($fullPath) ? $fullPath : null;
	}

	private function absolute(string $relativePath): string
	{
		return $this->config->datadir . '/' . $relativePath;
	}
}

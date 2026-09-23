<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Export\Service;

/**
 * A zip being written to a temp file: the open/close prelude and the tree
 * walk that skips `.cache` folders, shared by the collection and object
 * zippers.
 */
final class ZipBuilder
{
	private readonly \ZipArchive $zip;
	private int $entries = 0;

	private function __construct(private readonly string $path)
	{
		$this->zip = new \ZipArchive();
		$result    = $this->zip->open($this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		if ($result !== true) {
			throw new \RuntimeException(sprintf('Failed to create zip file: %s (Error code: %d)', $this->path, $result));
		}
	}

	/** A zip at `{tmp}/{stem}-{unique}.zip`. */
	public static function temp(string $stem): self
	{
		return new self(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $stem . '-' . uniqid('', true) . '.zip');
	}

	public function path(): string
	{
		return $this->path;
	}

	public function entries(): int
	{
		return $this->entries;
	}

	public function addFile(string $realPath, string $zipPath): void
	{
		$this->zip->addFile($realPath, $zipPath);
		$this->entries++;
	}

	/** Add a directory's contents under `$zipPath`, leaving out `.cache` folders. */
	public function addTree(string $realPath, string $zipPath): void
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
			$filePath     = (string)$file->getRealPath();
			$relativePath = substr($filePath, strlen($canonicalPath) + 1);

			if (str_contains($relativePath, '.cache')) {
				continue;
			}

			$zipFilePath = $zipPath . DIRECTORY_SEPARATOR . $relativePath;

			if ($file->isDir()) {
				$this->zip->addEmptyDir($zipFilePath);
				$this->entries++;
			} elseif ($file->isFile()) {
				$this->addFile($filePath, $zipFilePath);
			}
		}
	}

	/** Whether a directory holds anything besides its `.cache` folder. */
	public static function hasNonCacheContents(string $dir): bool
	{
		foreach (new \DirectoryIterator($dir) as $file) {
			if ($file->isDot() || $file->getFilename() === '.cache') {
				continue;
			}

			return true;
		}

		return false;
	}

	/** Write the archive and return its path. */
	public function close(): string
	{
		$this->zip->close();

		return $this->path;
	}

	/** Abandon the archive. ZipArchive writes nothing for an empty zip, so the unlink is conditional. */
	public function discard(): void
	{
		$this->zip->close();
		if (file_exists($this->path)) {
			unlink($this->path);
		}
	}
}

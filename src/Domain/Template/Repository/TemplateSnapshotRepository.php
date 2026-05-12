<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Repository;

use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Storage for per-template version-history snapshots.
 *
 * Snapshots live under `tcms-data/builder/.history/<template-path>/<unix-ts>.twig`.
 * For `pages/about.twig`, history goes in `.history/pages/about/`. Nested
 * templates work the same: `pages/blog/post.twig` → `.history/pages/blog/post/`.
 *
 * The repository deals only in I/O — retention policy and capture sequencing
 * live in {@see \TotalCMS\Domain\Template\Service\TemplateSnapshotService}.
 */
class TemplateSnapshotRepository extends StorageRepository
{
	public const HISTORY_DIR = TemplateRepository::BUILDER_DIR . '.history/';

	public function exists(string $id, ?string $folder, int $timestamp): bool
	{
		return $this->filesystem->fileExists($this->snapshotPath($id, $folder, $timestamp));
	}

	/**
	 * Read a snapshot's contents. Returns null when the file is absent so
	 * the service can decide whether that's an error.
	 */
	public function read(string $id, ?string $folder, int $timestamp): ?string
	{
		$path = $this->snapshotPath($id, $folder, $timestamp);
		if (!$this->filesystem->fileExists($path)) {
			return null;
		}

		return $this->filesystem->read($path);
	}

	public function write(string $id, ?string $folder, int $timestamp, string $contents): void
	{
		$this->filesystem->write($this->snapshotPath($id, $folder, $timestamp), $contents);
	}

	public function delete(string $id, ?string $folder, int $timestamp): void
	{
		$this->filesystem->delete($this->snapshotPath($id, $folder, $timestamp));
	}

	/**
	 * List snapshot timestamps for a template, in filesystem order.
	 *
	 * @return list<int>
	 */
	public function listTimestamps(string $id, ?string $folder): array
	{
		$dir = $this->dirFor($id, $folder);
		if (!$this->filesystem->directoryExists($dir)) {
			return [];
		}

		$timestamps = [];
		foreach ($this->filesystem->flysystem()->listContents($dir) as $item) {
			if (!$item->isFile()) {
				continue;
			}
			$basename = basename($item->path());
			if (preg_match('/^(\d+)\.twig$/', $basename, $m) === 1) {
				$timestamps[] = (int)$m[1];
			}
		}

		return $timestamps;
	}

	private function snapshotPath(string $id, ?string $folder, int $timestamp): string
	{
		return $this->dirFor($id, $folder) . '/' . $timestamp . '.twig';
	}

	/**
	 * Build the history folder path for a template, rejecting path traversal.
	 *
	 * @throws \InvalidArgumentException When the resulting relative path is
	 *         empty or contains `..` (prevents escaping the history root).
	 */
	private function dirFor(string $id, ?string $folder): string
	{
		$relative = $folder !== null && $folder !== '' ? trim($folder, '/') . '/' . $id : $id;
		$relative = trim($relative, '/');

		if ($relative === '' || str_contains($relative, '..')) {
			throw new \InvalidArgumentException('Invalid template snapshot path');
		}

		return self::HISTORY_DIR . $relative;
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Export\Service;

use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Service for creating zip files of one or more objects' data and assets.
 * Each object lands at the zip root as its file (`{id}.json` or `{id}.md`)
 * plus its `{id}/` assets folder.
 */
readonly class ObjectZipper
{
	public function __construct(
		private Config $config,
		private ObjectRepository $objects,
	) {
	}

	/**
	 * @throws \RuntimeException If zip creation fails or object not found
	 *
	 * @return string The path to the created zip file
	 */
	public function createObjectZip(string $collection, string $id): string
	{
		if ($this->objects->objectPath($collection, $id) === null) {
			throw new \RuntimeException("Object not found: {$collection}/{$id}");
		}

		return $this->zipObjects("object-{$collection}-{$id}", $collection, [$id]);
	}

	/**
	 * Ids that no longer exist are skipped.
	 *
	 * @param array<int,string> $ids
	 *
	 * @throws \RuntimeException If zip creation fails or no ids resolve to objects
	 *
	 * @return string The path to the created zip file
	 */
	public function createObjectsZip(string $collection, array $ids): string
	{
		return $this->zipObjects("objects-{$collection}", $collection, $ids);
	}

	/** @param array<int,string> $ids */
	private function zipObjects(string $stem, string $collection, array $ids): string
	{
		$datadir = $this->config->datadir;
		$zip     = ZipBuilder::temp($stem);
		$added   = 0;

		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id === '') {
				continue;
			}

			$objectFile = $this->objects->objectPath($collection, $id);
			if ($objectFile === null) {
				continue;
			}

			$zip->addFile(PathUtils::absolutePath($datadir, $objectFile), basename($objectFile));

			$assetsPath = PathUtils::absolutePath($datadir, PathUtils::buildPath(collection: $collection, filename: $id));
			if (is_dir($assetsPath) && ZipBuilder::hasNonCacheContents($assetsPath)) {
				$zip->addTree($assetsPath, $id);
			}

			$added++;
		}

		if ($added === 0) {
			$zip->discard();
			throw new \RuntimeException("No objects found to export in collection: {$collection}");
		}

		return $zip->close();
	}

	/**
	 * Get the filename for the zip download.
	 */
	public function getZipFilename(string $collection, string $id): string
	{
		return sprintf('%s--%s.zip', $collection, $id);
	}

	/**
	 * Get the filename for a multi-object zip download.
	 */
	public function getObjectsZipFilename(string $collection): string
	{
		return sprintf('%s-objects.zip', $collection);
	}
}

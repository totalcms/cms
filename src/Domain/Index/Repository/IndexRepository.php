<?php

namespace TotalCMS\Domain\Index\Repository;

use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\PathUtils;

/**
 * Repository.
 */
final class IndexRepository extends StorageRepository
{
	private const INDEX_FILE = '.index.json';

	/**
	 * get the index.
	 *
	 * @param string $collection
	 *
	 * @return ?IndexData
	 */
	public function fetchIndex(string $collection): ?IndexData
	{
		$indexFile = $this->buildIndexPath($collection);

		return $this->fetchAndDeserialize($indexFile, IndexData::class);
	}

	/**
	 * Get an array of object IDs in.
	 *
	 * @param string $collection
	 *
	 * @return array<string>
	 */
	public function fetchObjectIds(string $collection): array
	{
		$files = $this->filesystem->listFiles($collection);

		return array_map(fn (string $path) => basename($path, StorageRepository::FILE_EXT), $files);
	}

	/**
	 * save the index.
	 *
	 * @param string $collection
	 * @param IndexData $index
	 *
	 * @return void
	 */
	public function saveIndex(string $collection, IndexData $index): void
	{
		$indexFile  = $this->buildIndexPath($collection);
		$indexJSON  = $this->serializer->serialize($index, 'json'); // no pretty print on purpose

		$this->filesystem->write($indexFile, $indexJSON);
	}

	private function buildIndexPath(string $collection): string
	{
		return PathUtils::buildPath(collection: $collection, filename: self::INDEX_FILE);
	}
}

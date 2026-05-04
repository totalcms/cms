<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Stores the Site Builder pages' hierarchical order tree as a JSON file at
 * `tcms-data/{collection}/.order.json`.
 *
 * The repository deals only in raw nested-array trees — `list<{id, children}>`.
 * Reconciliation against the page index, legacy migration, and parent-map
 * walking all live in {@see \TotalCMS\Domain\Builder\Service\BuilderOrderService}.
 */
class BuilderOrderRepository extends StorageRepository
{
	public const ORDER_FILE = '.order.json';

	public function exists(string $collectionId): bool
	{
		return $this->filesystem->fileExists($this->orderPath($collectionId));
	}

	/**
	 * Read the raw tree from disk. Returns an empty list when the file is
	 * missing or contains malformed JSON — the service reconciles before
	 * exposing anything to callers.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function read(string $collectionId): array
	{
		$path = $this->orderPath($collectionId);
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}

		$raw = json_decode($this->filesystem->read($path), true);
		if (!is_array($raw)) {
			return [];
		}

		/** @var list<array<string,mixed>> $tree */
		$tree = array_values(array_filter($raw, is_array(...)));

		return $tree;
	}

	/**
	 * Persist a reconciled tree.
	 *
	 * @param list<array{id:string,children:list<array<string,mixed>>}> $tree
	 */
	public function write(string $collectionId, array $tree): void
	{
		$json = (string)json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$this->filesystem->write($this->orderPath($collectionId), $json);
	}

	private function orderPath(string $collectionId): string
	{
		return PathUtils::buildPath(collection: $collectionId, filename: self::ORDER_FILE);
	}
}

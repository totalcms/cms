<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * Coordinates the Site Builder pages' hierarchical order tree.
 *
 * The order tree stores ONLY hierarchy and ordering — page content (title,
 * route, template, etc.) lives in the page records themselves. Storing
 * order separately means a reorder operation is a single small file write
 * instead of N page record writes, and editing a page can never silently
 * undo a reorder.
 *
 * File shape — a tree where each node is `{id, children: []}`:
 *
 *   [
 *     {"id": "home",    "children": []},
 *     {"id": "blog",    "children": [
 *       {"id": "blog-post", "children": []}
 *     ]},
 *     {"id": "about",   "children": []}
 *   ]
 *
 * Order is implicit in array index. Hierarchy is implicit in nesting.
 *
 * Read operations reconcile against the current page list:
 * - IDs in the order file that no longer exist as pages are dropped
 * - Pages that exist but aren't in the order file are appended at root
 *
 * This keeps the system robust without needing event listeners — adding or
 * deleting a page record automatically reflects in the order on next read.
 *
 * Storage I/O lives in {@see BuilderOrderRepository}; this service handles
 * reconciliation, legacy migration, and tree-walking helpers.
 */
readonly class BuilderOrderService
{
	public function __construct(
		private BuilderOrderRepository $repository,
		private IndexReader $indexReader,
	) {
	}

	/**
	 * Read the order tree for a collection, reconciled against the current
	 * page list. Always returns a tree containing every existing page exactly
	 * once. Triggers a one-time migration from legacy `parent`/`sort` fields
	 * if the order file doesn't exist yet.
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	public function read(string $collectionId): array
	{
		if (!$this->repository->exists($collectionId)) {
			$tree = $this->migrateFromPageRecords($collectionId);
			$this->repository->write($collectionId, $tree);

			return $tree;
		}

		return $this->reconcile(
			$this->repository->read($collectionId),
			$this->fetchExistingPageIds($collectionId),
		);
	}

	/**
	 * Write a new order tree. Filters out IDs that don't exist as pages,
	 * deduplicates, then writes a clean tree. Returns the cleaned tree.
	 *
	 * @param  list<array<string,mixed>> $tree
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	public function write(string $collectionId, array $tree): array
	{
		$existingIds = $this->fetchExistingPageIds($collectionId);
		$cleaned     = $this->reconcile($tree, $existingIds);
		$this->repository->write($collectionId, $cleaned);

		return $cleaned;
	}

	/**
	 * Build a flat `id => parent` map from the order tree, useful for code
	 * that still needs to know an individual page's parent.
	 *
	 * @return array<string,string>
	 */
	public function parentMap(string $collectionId): array
	{
		$map = [];
		$this->walk($this->read($collectionId), '', static function (string $id, string $parent) use (&$map): void {
			$map[$id] = $parent;
		});

		return $map;
	}

	/**
	 * Find the immediate parent of a page id, or '' if root or not found.
	 */
	public function parentOf(string $collectionId, string $pageId): string
	{
		return $this->parentMap($collectionId)[$pageId] ?? '';
	}

	// --- internals ---

	/**
	 * Reconcile a stored tree against the actual page list:
	 * - Drop nodes whose id no longer exists
	 * - Drop duplicates (first occurrence wins)
	 * - Append pages not present anywhere in the tree at the root, in id order
	 *
	 * @param  list<array<string,mixed>>|array<int,mixed> $tree
	 * @param  list<string>                               $existingIds
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	private function reconcile(array $tree, array $existingIds): array
	{
		$existing = array_flip($existingIds);
		$seen     = [];

		$clean = $this->cleanNodes($tree, $existing, $seen);

		// Append pages that exist but aren't anywhere in the tree
		$missing = array_diff($existingIds, array_keys($seen));
		sort($missing);
		foreach ($missing as $id) {
			$clean[] = ['id' => $id, 'children' => []];
		}

		return $clean;
	}

	/**
	 * @param  list<array<string,mixed>>|array<int,mixed> $nodes
	 * @param  array<string,int>                          $existing  flip of valid ids
	 * @param  array<string,bool>                         $seen      (out param)
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	private function cleanNodes(array $nodes, array $existing, array &$seen): array
	{
		$out = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			$id       = (string)($node['id'] ?? '');
			$children = $node['children'] ?? [];
			if (!is_array($children)) {
				$children = [];
			}

			$cleanedChildren = $this->cleanNodes($children, $existing, $seen);

			// Page no longer exists OR id is empty / duplicate — splice its
			// children into this node's spot so nested grandchildren keep
			// their structure when an intermediate page is deleted.
			if ($id === '' || isset($seen[$id]) || !isset($existing[$id])) {
				foreach ($cleanedChildren as $child) {
					$out[] = $child;
				}

				continue;
			}

			$seen[$id] = true;
			$out[]     = [
				'id'       => $id,
				'children' => $cleanedChildren,
			];
		}

		return $out;
	}

	/**
	 * One-shot migration: build an order tree from the existing page records'
	 * `parent` + `sort` fields. Only runs when the order file doesn't exist.
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	private function migrateFromPageRecords(string $collectionId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Throwable) {
			return [];
		}

		// Group ids by parent, preserving the index's natural order then sort
		// each group by the legacy sort field.
		/** @var array<string,list<array{id:string,sort:int}>> $byParent */
		$byParent = [];
		foreach ($index->objects as $obj) {
			$id = (string)($obj['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$parent              = (string)($obj['parent'] ?? '');
			$sort                = (int)($obj['sort'] ?? 0);
			$byParent[$parent][] = ['id' => $id, 'sort' => $sort];
		}

		foreach ($byParent as &$kids) {
			usort($kids, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
		}
		unset($kids);

		return $this->buildSubtree('', $byParent);
	}

	/**
	 * @param  array<string,list<array{id:string,sort:int}>> $byParent
	 *
	 * @return list<array{id:string,children:list<array<string,mixed>>}>
	 */
	private function buildSubtree(string $parentId, array $byParent): array
	{
		$nodes = [];
		foreach ($byParent[$parentId] ?? [] as $entry) {
			$nodes[] = [
				'id'       => $entry['id'],
				'children' => $this->buildSubtree($entry['id'], $byParent),
			];
		}

		return $nodes;
	}

	/**
	 * @return list<string>
	 */
	private function fetchExistingPageIds(string $collectionId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Throwable) {
			return [];
		}

		$ids = [];
		foreach ($index->objects as $obj) {
			$id = (string)($obj['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Walk every node in the tree, invoking $fn(id, parentId) per node.
	 *
	 * @param array<int,mixed> $tree
	 * @param callable(string, string): void $fn
	 */
	private function walk(array $tree, string $parentId, callable $fn): void
	{
		foreach ($tree as $node) {
			if (!is_array($node)) {
				continue;
			}
			$id = (string)($node['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$fn($id, $parentId);
			$children = $node['children'] ?? [];
			if (is_array($children)) {
				$this->walk($children, $id, $fn);
			}
		}
	}
}

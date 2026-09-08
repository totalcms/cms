<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * Site Builder navigation behind `cms.builder.nav()`, `subnav()`, `navTree()`
 * and `pagesTree()`: the page order file hydrated with each page's record,
 * with drafts and nav-hidden pages dropped for the public views.
 *
 * BuilderTwigAdapter is the Twig-facing entry point and delegates here.
 */
class BuilderNavigation
{
	public function __construct(
		private readonly BuilderConfigService $builderConfig,
		private readonly IndexReader $indexReader,
		private readonly BuilderOrderService $orderService,
	) {
	}

	/**
	 * Get top-level navigation pages (no parent).
	 *
	 * Returns published, nav-visible pages in their stored order.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function nav(?string $collection = null): array
	{
		$tree = $this->navTree($collection);

		// Strip nested children — nav() returns flat top-level only
		return array_map(static function (array $node): array {
			unset($node['children']);

			return $node;
		}, $tree);
	}

	/**
	 * Get child navigation pages for a specific parent.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function subnav(string $parentId, ?string $collection = null): array
	{
		$node = $this->findNode($this->navTree($collection), $parentId);
		if ($node === null) {
			return [];
		}

		$children = $node['children'] ?? [];
		if (!is_array($children)) {
			return [];
		}

		return array_map(static function (array $child): array {
			unset($child['children']);

			return $child;
		}, $children);
	}

	/**
	 * Get the navigation tree (published, nav-visible pages) with children
	 * nested under each parent. Each page node carries its full record plus
	 * a `children` array.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function navTree(?string $collection = null): array
	{
		return $this->hydrateOrderTree($collection, true);
	}

	/**
	 * Get every page as a nested tree — no draft/nav filtering. Intended for
	 * the admin sidebar where every page must be visible and editable.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function pagesTree(?string $collection = null): array
	{
		return $this->hydrateOrderTree($collection, false);
	}

	/**
	 * Walk the order-file tree and attach each node's full page record from
	 * the index. When $publicOnly is true, drafts and nav-hidden pages are
	 * dropped (and any children of dropped pages are dropped too).
	 *
	 * @return array<array<string,mixed>>
	 */
	private function hydrateOrderTree(?string $collection, bool $publicOnly): array
	{
		$collectionId = $collection ?? $this->builderConfig->getPagesCollectionId();
		$pageById     = $this->fetchPageRecordsById($collectionId);

		if ($pageById === []) {
			return [];
		}

		$tree = $this->orderService->read($collectionId);

		return $this->attachRecords($tree, $pageById, $publicOnly);
	}

	/**
	 * @param  list<array{id:string,children:list<array<string,mixed>>}> $tree
	 * @param  array<string,array<string,mixed>>                          $pageById
	 *
	 * @return list<array<string,mixed>>
	 */
	private function attachRecords(array $tree, array $pageById, bool $publicOnly): array
	{
		$out = [];
		foreach ($tree as $node) {
			$id = $node['id'];
			if (!isset($pageById[$id])) {
				continue;
			}
			$record = $pageById[$id];

			if ($publicOnly && (!empty($record['draft']) || ($record['nav'] ?? true) !== true)) {
				continue;
			}

			$childrenRaw = $node['children'];
			/** @var list<array{id:string,children:list<array<string,mixed>>}> $childrenRaw */
			$record['children'] = $this->attachRecords($childrenRaw, $pageById, $publicOnly);
			$out[]              = $record;
		}

		return $out;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchPageRecordsById(string $collectionId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Exception) {
			return [];
		}

		$by = [];
		foreach ($index->objects as $page) {
			$id = (string)($page['id'] ?? '');
			if ($id !== '') {
				$by[$id] = $page;
			}
		}

		return $by;
	}

	/**
	 * Find a node by id anywhere in a tree.
	 *
	 * @param  array<array<string,mixed>>  $tree
	 *
	 * @return array<string,mixed>|null
	 */
	private function findNode(array $tree, string $id): ?array
	{
		foreach ($tree as $node) {
			if ((string)($node['id'] ?? '') === $id) {
				return $node;
			}
			$children = $node['children'] ?? [];
			if (is_array($children)) {
				$found = $this->findNode($children, $id);
				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
	}
}

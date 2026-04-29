<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * Twig sub-adapter for builder navigation helpers.
 *
 * Accessed in Twig as `cms.builder.*`.
 */
readonly class BuilderTwigAdapter
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
	) {
	}

	/**
	 * Get top-level navigation pages (where parent is empty).
	 *
	 * Returns published, nav-visible pages sorted by sort order.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function nav(?string $collection = null): array
	{
		$pages = $this->fetchNavPages($collection);

		return array_values(array_filter($pages, fn (array $page): bool => ($page['parent'] ?? '') === ''));
	}

	/**
	 * Get child navigation pages for a specific parent.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function subnav(string $parentId, ?string $collection = null): array
	{
		$pages = $this->fetchNavPages($collection);

		return array_values(array_filter($pages, fn (array $page): bool => ($page['parent'] ?? '') === $parentId));
	}

	/**
	 * Get the full navigation tree with nested children.
	 *
	 * Each page gets a `children` key containing its child pages, recursively.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function navTree(?string $collection = null): array
	{
		return $this->buildTree($this->fetchNavPages($collection));
	}

	/**
	 * Fetch all navigation-ready pages: published, nav-visible, sorted.
	 *
	 * @return array<array<string,mixed>>
	 */
	private function fetchNavPages(?string $collection): array
	{
		$collectionId = $collection ?? $this->builderConfig->getPagesCollectionId();

		try {
			$index = $this->indexReader->fetchIndex($collectionId);
		} catch (\Exception) {
			return [];
		}

		return $index->objects
			->filter(fn (array $page): bool => empty($page['draft']) && ($page['nav'] ?? true) === true)
			->sortBy('sort')
			->values()
			->toArray();
	}

	/**
	 * Build a nested tree from a flat list of pages using the parent field.
	 *
	 * @param array<array<string,mixed>> $pages
	 *
	 * @return array<array<string,mixed>>
	 */
	private function buildTree(array $pages): array
	{
		// Group pages by parent
		$byParent = [];
		foreach ($pages as $page) {
			$parent = (string)($page['parent'] ?? '');
			$byParent[$parent][] = $page;
		}

		// Recursively attach children
		$attach = function (array $page) use (&$attach, $byParent): array {
			$id = (string)($page['id'] ?? '');
			$children = $byParent[$id] ?? [];
			$page['children'] = array_map($attach, $children);

			return $page;
		};

		// Start from top-level (empty parent)
		$roots = $byParent[''] ?? [];

		return array_map($attach, $roots);
	}
}

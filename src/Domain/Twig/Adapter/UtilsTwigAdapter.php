<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

/**
 * Twig utility functions available as cms.utils.*.
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
readonly class UtilsTwigAdapter
{
	/**
	 * Read URL query parameters and convert them into include/exclude/sort/search options.
	 *
	 * Property filters use the param name as the field and the value as the match:
	 *   ?category=travel       → include: "category:travel"
	 *   ?tag=-beach            → exclude: "tag:beach"  (- prefix = exclude)
	 *   ?category=travel&tag=food → include: "category:travel,tag:food"
	 *
	 * Sort and search use configurable param names:
	 *   ?sort=-date            → sort: "-date"
	 *   ?q=adventure           → search: "adventure"
	 *
	 * @param array<string,string> $options Configuration:
	 *   - sort: URL param name for sort (default: "sort")
	 *   - search: URL param name for search (default: "search")
	 *   - ignore: comma-separated param names to skip (default: "")
	 *
	 * @return array{include:string,exclude:string,sort:string,search:string}
	 */
	public function urlFilters(array $options = []): array
	{
		$sortParam   = (string)($options['sort'] ?? 'sort');
		$searchParam = (string)($options['search'] ?? 'search');
		$ignoreList  = array_filter(array_map(trim(...), explode(',', (string)($options['ignore'] ?? ''))));

		// Reserved param names that are never treated as property filters
		$reserved = array_merge([$sortParam, $searchParam], $ignoreList);

		$queryParams = $_GET;

		$includes = [];
		$excludes = [];
		$sort     = '';
		$search   = '';

		foreach ($queryParams as $key => $value) {
			$key = (string)$key;

			// Handle reserved params (sort, search)
			if (in_array($key, $reserved, true)) {
				if ($key === $sortParam) {
					$sort = is_array($value) ? (string)($value[0] ?? '') : (string)$value;
				} elseif ($key === $searchParam) {
					$search = is_array($value) ? (string)($value[0] ?? '') : (string)$value;
				}
				continue;
			}

			// Normalize to array for consistent handling (supports ?tags[]=a&tags[]=b)
			$values = is_array($value) ? $value : [$value];

			foreach ($values as $v) {
				$v = trim((string)$v);
				if ($v === '') {
					continue;
				}

				// - prefix on value means exclude
				if (str_starts_with($v, '-')) {
					$excludes[] = $key . ':' . substr($v, 1);
				} else {
					$includes[] = $key . ':' . $v;
				}
			}
		}

		return [
			'include' => implode(',', $includes),
			'exclude' => implode(',', $excludes),
			'sort'    => $sort,
			'search'  => $search,
		];
	}
}

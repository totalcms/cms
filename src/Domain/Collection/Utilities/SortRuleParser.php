<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Utilities;

/**
 * Parses sort query string format into CollectionSorter rules.
 *
 * Format: "property:direction[:natural]" comma-separated.
 * Examples:
 *   "date:desc"              → sort by date descending
 *   "title:asc:natural"      → sort by title ascending with natural sort
 *   "date:desc,title:asc"    → multi-criteria sort
 *   "shuffle"                → random order
 */
class SortRuleParser
{
	/**
	 * Parse a sort query string into CollectionSorter-compatible rules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function parse(string $sortString): array
	{
		if ($sortString === '') {
			return [];
		}

		$rules = [];
		$parts = explode(',', $sortString);

		foreach ($parts as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}

			if (strtolower($part) === 'shuffle') {
				$rules[] = ['shuffle' => true];
				continue;
			}

			$segments = explode(':', $part);
			$property = trim($segments[0]);
			if ($property === '') {
				continue;
			}

			$direction = isset($segments[1]) ? strtolower(trim($segments[1])) : 'asc';
			$natural   = isset($segments[2]) && strtolower(trim($segments[2])) === 'natural';

			$rules[] = [
				'property' => $property,
				'reverse'  => $direction === 'desc',
				'natural'  => $natural,
			];
		}

		return $rules;
	}
}

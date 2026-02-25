<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Query\Service;

/**
 * Generic in-memory full-text search on arrays of objects.
 *
 * Supports AND/OR logic and quoted phrases.
 */
readonly class ObjectSearcher
{
	/**
	 * Search items using in-memory full-text matching.
	 *
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search(array $items, string $query): array
	{
		$query = trim(mb_strtolower($query));
		if ($query === '') {
			return [];
		}

		$terms = $this->parseTerms($query);
		$terms = array_diff($terms, ['and']);

		$matchOR = false;
		if (in_array('or', $terms, true)) {
			$matchOR = true;
			$terms   = array_values(array_diff($terms, ['or']));
		}

		if ($terms === []) {
			return [];
		}

		return array_values(array_filter($items, static function (array $item) use ($terms, $matchOR): bool {
			if ($matchOR) {
				foreach ($terms as $term) {
					if (self::itemMatchesTerm($item, $term)) {
						return true;
					}
				}

				return false;
			}

			foreach ($terms as $term) {
				if (!self::itemMatchesTerm($item, $term)) {
					return false;
				}
			}

			return true;
		}));
	}

	/**
	 * Parse query string into individual terms, supporting quoted phrases.
	 *
	 * @return array<string>
	 */
	private function parseTerms(string $query): array
	{
		preg_match_all('/"([^"]+)"|(\S+)/', $query, $matches);

		return array_map(
			static fn (string $t1, string $t2): string => $t1 !== '' ? $t1 : $t2,
			$matches[1],
			$matches[2],
		);
	}

	/**
	 * Check if an item matches a single search term across all its values.
	 *
	 * @param array<string,mixed> $item
	 */
	private static function itemMatchesTerm(array $item, string $term): bool
	{
		foreach ($item as $value) {
			if (empty($value)) {
				continue;
			}

			if (is_array($value)) {
				if (self::searchArrayValues($value, $term)) {
					return true;
				}
			} elseif (is_scalar($value)) {
				$pattern = '/\b' . preg_quote($term, '/') . '/i';
				if (preg_match($pattern, (string)$value) === 1) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively search array values for a term.
	 *
	 * @param array<mixed> $values
	 */
	private static function searchArrayValues(array $values, string $term): bool
	{
		foreach ($values as $value) {
			if (empty($value)) {
				continue;
			}

			if (is_array($value)) {
				if (self::searchArrayValues($value, $term)) {
					return true;
				}
			} elseif (is_scalar($value)) {
				$pattern = '/\b' . preg_quote($term, '/') . '/i';
				if (preg_match($pattern, (string)$value) === 1) {
					return true;
				}
			}
		}

		return false;
	}
}

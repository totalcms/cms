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
	 * Relevance-scored search. Unlike search() (a boolean AND/OR filter), this
	 * returns every item matching AT LEAST ONE term (OR recall), scored by the
	 * number of distinct terms matched, weighted by field.
	 *
	 * Ordering is AND-biased: more distinct terms matched wins first (a
	 * full-coverage match always outranks a partial one); ties break by
	 * weighted score, then by `id` ascending for determinism.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,float> $weights field name => weight (unlisted = 1.0)
	 *
	 * @return list<array{item: array<string,mixed>, score: float, matched: int}>
	 */
	public function searchScored(array $items, string $query, array $weights = []): array
	{
		$query = trim(mb_strtolower($query));
		if ($query === '') {
			return [];
		}

		// Lowercased above so 'AND'/'OR' are stripped too (mirrors search()).
		$terms = array_values(array_diff($this->parseTerms($query), ['and', 'or']));
		if ($terms === []) {
			return [];
		}

		$scored = [];
		foreach ($items as $item) {
			$matched = 0;
			$score   = 0.0;
			foreach ($terms as $term) {
				$weight = $this->bestFieldWeight($item, $term, $weights);
				if ($weight !== null) {
					$matched++;
					$score += $weight;
				}
			}
			if ($matched === 0) {
				continue;
			}
			$scored[] = ['item' => $item, 'score' => $score, 'matched' => $matched];
		}

		usort($scored, static fn (array $a, array $b): int => ($b['matched'] <=> $a['matched'])
				?: (($b['score'] <=> $a['score'])
				?: ((string)($a['item']['id'] ?? '') <=> (string)($b['item']['id'] ?? ''))));

		return $scored;
	}

	/**
	 * Highest field weight among the fields of $item where $term matches, or
	 * null when the term matches no field. Uses the same word-prefix + recursive
	 * array rule as itemMatchesTerm(). Fields absent from $weights count as 1.0.
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,float> $weights
	 */
	private function bestFieldWeight(array $item, string $term, array $weights): ?float
	{
		$best = null;
		foreach ($item as $key => $value) {
			if (empty($value)) {
				continue;
			}

			if (is_array($value)) {
				$matches = self::searchArrayValues($value, $term);
			} elseif (is_scalar($value)) {
				$matches = $this->scalarMatchesTerm((string)$value, $term);
			} else {
				$matches = false;
			}

			if ($matches) {
				$weight = $weights[$key] ?? 1.0;
				if ($best === null || $weight > $best) {
					$best = $weight;
				}
			}
		}

		return $best;
	}

	/**
	 * Word-prefix match for a single scalar value — the shared rule used by
	 * itemMatchesTerm()/searchArrayValues() for scalars.
	 */
	private function scalarMatchesTerm(string $value, string $term): bool
	{
		return preg_match('/\b' . preg_quote($term, '/') . '/i', $value) === 1;
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

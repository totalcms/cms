<?php

namespace TotalCMS\Domain\Index\Service;

use Illuminate\Support\Collection;

final class IndexSearcher
{
	public function __construct(
		private IndexReader $reader,
	) {}

	/** @return Collection<int,array<string,mixed>> */
	public function searchByProperty(string $collection, string $property, string $query): Collection
	{
		$index = $this->reader->fetchIndex($collection);

		if (is_null($index)) {
			return collect([]);
		}

		$objects = $index->objects->filter(function ($object) use ($property, $query) {
			return stripos($object[$property], $query) !== false;
		});

		return $objects;
	}

	/**
	 * @param array<string> $priorityFields
	 *
	 * @return Collection<int,array<string,mixed>>
	 */
	public function search(string $collection, string $query, array $priorityFields = []): Collection
	{
		$index = $this->reader->fetchIndex($collection);

		if (is_null($index) || empty($query)) {
			return collect([]);
		}

		$queries = self::splitQuery($query);
		$queries = array_diff($queries, ['and']);
		$results = $index->objects;
		$matchOR = false;

		if (in_array('or', $queries)) {
			$matchOR = true;
			$queries = array_diff($queries, ['or']);
		}

		if (empty($queries)) {
			// edge case if someone searches for "and" or "or"
			return collect([]);
		}

		// Filter the collection based on match logic
		$results = $results->filter(function ($object) use ($queries, $matchOR) {
			if ($matchOR) {
				return self::filterOR($object, $queries);
			}
			return self::filterAND($object, $queries);
		});

		if (!empty($priorityFields)) {
			// Sort the filtered collection by priority
			$results = $results->sort(function ($a, $b) use ($priorityFields, $queries) {
				$priorityA = self::getPriorityScore($a, $priorityFields, $queries);
				$priorityB = self::getPriorityScore($b, $priorityFields, $queries);
				return $priorityA <=> $priorityB;
			})->values(); // Reindex the sorted collection
		}

		return $results;
	}

	/**
	 * @param array<mixed> $object
	 * @param array<string> $queries
	 */
	private static function filterOR(array $object, array $queries): bool
	{
		foreach ($queries as $query) {
			if (self::searchArray($object, $query)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<mixed> $object
	 * @param array<string> $queries
	 */
	private static function filterAND(array $object, array $queries): bool
	{
		foreach ($queries as $query) {
			if (!self::searchArray($object, $query)) {
				return false; // If any query does not match, exclude the object
			}
		}
		return true; // All queries matched
	}

	/**
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $item
	 */
	private static function searchArray(array $item, string $query): bool
	{
		foreach ($item as $value) {
			if (empty($value)) {
				continue;
			}
			if (is_array($value)) {
				// Recursively search in nested arrays
				if (self::searchArray($value, $query)) {
					return true;
				}
			} else {
				// Perform case-insensitive search for string values
				if (stripos($value, $query) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array<string> */
	private static function splitQuery(string $query): array
	{
		$query = mb_strtolower($query);
		// Modified regex to capture terms without quotes
		preg_match_all('/"([^"]+)"|(\S+)/', $query, $matches);

		// Extract the terms from the matches, removing quotes
		$terms = array_map(function ($term1, $term2) {
			return $term1 ?: $term2; // Use non-empty value
		}, $matches[1], $matches[2]);

		return $terms;
	}

	/**
	 * Function to get the priority score of an item based on the fields array
	 *
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $item
	 * @param array<string> $fields
	 * @param array<string> $queries
	 */
	private static function getPriorityScore(array $item, array $fields, array $queries): int
	{
		foreach ($fields as $index => $field) {
			if (!array_key_exists($field, $item)) {
				continue;
			}
			$value = $item[$field];
			foreach ($queries as $query) {
				if (is_array($value)) {
					if (self::searchArray($value, $query)) {
						return $index;
					}
				} else {
					if (stripos($value, $query) !== false) {
						return $index;
					}
				}
			}
		}
		return PHP_INT_MAX;
	}
}

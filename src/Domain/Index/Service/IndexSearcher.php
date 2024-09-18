<?php

namespace TotalCMS\Domain\Index\Service;

use Illuminate\Support\Collection;

final class IndexSearcher
{
	public function __construct(
		private IndexReader $reader,
	) {
	}

	/** @return Collection<int,array<string,mixed>> */
	public function searchByProperty(string $collection, string $property, string $query): Collection
	{
		$index = $this->reader->fetchIndex($collection);

		if (is_null($index)) {
			return collect([]);
		}

		$objects = $index->objects->filter(function ($object) use ($property, $query) {
			return self::searchProperty($object, $property, $query);
		});

		return $objects;
	}

	/**
	 * @param array<string> $priorityProperties
	 *
	 * @return Collection<int,array<string,mixed>>
	 */
	public function search(string $collection, string $query, array $priorityProperties = []): Collection
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

		if (!empty($priorityProperties)) {
			// Sort the filtered collection by priority
			$results = $results->sort(function ($a, $b) use ($priorityProperties, $queries) {
				$priorityA = self::getPriorityScore($a, $priorityProperties, $queries);
				$priorityB = self::getPriorityScore($b, $priorityProperties, $queries);

				return $priorityA <=> $priorityB;
			})->values(); // Reindex the sorted collection
		}

		return $results;
	}

	/**
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $object
	 * @param array<string> $queries
	 */
	private static function filterOR(array $object, array $queries): bool
	{
		foreach ($queries as $query) {
			if (self::matchPropertyQuery($query)) {
				[$property, $searchTerm] = self::extractPropertyQuery($query);

				if (self::searchProperty($object, $property, $searchTerm)) {
					return true;
				}
			} else {
				if (self::searchArray($object, $query)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $object
	 * @param array<string> $queries
	 */
	private static function filterAND(array $object, array $queries): bool
	{
		foreach ($queries as $query) {
			if (self::matchPropertyQuery($query)) {
				[$property, $searchTerm] = self::extractPropertyQuery($query);

				if (!self::searchProperty($object, $property, $searchTerm)) {
					return false; // If any query does not match, exclude the object
				}
			} else {
				if (!self::searchArray($object, $query)) {
					return false; // If any query does not match, exclude the object
				}
			}
		}

		return true; // All queries matched
	}

	private static function matchPropertyQuery(string $query): bool
	{
		return strpos($query, ':') !== false;
	}

	/** @return array<string> */
	private static function extractPropertyQuery(string $query): array
	{
		return explode(':', $query, 2);
	}

	private static function searchValue(mixed $value, string $query): bool
	{
		if (is_scalar($value)) { // Checks if value is a string, int, float, or bool
			return stripos((string)$value, $query) !== false;
		}

		return false;
	}

	/**
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $object
	 */
	private static function searchArray(array $object, string $query): bool
	{
		foreach ($object as $value) {
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
				if (self::searchValue($value, $query)) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<mixed> $object */
	private static function searchProperty(array $object, string $property, string $query): bool
	{
		if (array_key_exists($property, $object)) {
			return self::searchValue($object[$property], $query);
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
	 * Function to get the priority score of an item based on the properties array.
	 *
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $object
	 * @param array<string> $properties
	 * @param array<string> $queries
	 */
	private static function getPriorityScore(array $object, array $properties, array $queries): int
	{
		foreach ($properties as $index => $property) {
			if (!array_key_exists($property, $object)) {
				continue;
			}
			$value = $object[$property];
			foreach ($queries as $query) {
				if (is_array($value)) {
					if (self::searchArray($value, $query)) {
						return $index;
					}
				} else {
					if (self::searchValue($value, $query)) {
						return $index;
					}
				}
			}
		}

		return PHP_INT_MAX;
	}
}

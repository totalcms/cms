<?php

namespace TotalCMS\Domain\Collection\Utilities;

use Illuminate\Support\Collection;

/**
 * Collection Sorter
 * sorts a collection of items.
 */
class CollectionSorter
{
	/** @var array<string,array<mixed>> Cache for extracted property values */
	private array $propertyCache = [];

	/**
	 * Constructor.
	 *
	 * @param array<array<string,mixed>> $collection
	 */
	public function __construct(
		private readonly array $collection,
	) {
	}

	/** @return array<array<string,mixed>> */
	public function shuffle(): array
	{
		$collection = $this->collection;
		shuffle($collection);

		return $collection;
	}

	/**
	 * Sort a collection by multiple criteria.
	 *
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * I tried to refactor this into static methods, but it was not working
	 *
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public function sortByRules(array $rules): array
	{
		// Early exit for common cases
		if (empty($rules) || empty($this->collection)) {
			return $this->collection;
		}

		if (count($this->collection) <= 1) {
			return $this->collection;
		}

		// Check for shuffle rule first (most efficient)
		foreach ($rules as $rule) {
			if (isset($rule['shuffle']) && boolval($rule['shuffle']) === true) {
				return $this->shuffle();
			}
		}

		// Pre-process and validate rules
		$processedRules = $this->preprocessRules($rules);
		if (empty($processedRules)) {
			return $this->collection;
		}

		// Pre-extract all property values to avoid repeated extraction
		$this->extractPropertyValues($processedRules);

		$collection = $this->collection;

		usort($collection, function ($a, $b) use ($processedRules) {
			// Get unique identifiers for cache lookup
			$aId = $this->getItemId($a);
			$bId = $this->getItemId($b);

			// Process rules in reverse order for logical sorting
			foreach (array_reverse($processedRules) as $rule) {
				$property = $rule['property'];
				$natsort  = $rule['natural'];
				$reverse  = $rule['reverse'];

				// Get cached values
				$aValue = $this->propertyCache[$property][$aId] ?? null;
				$bValue = $this->propertyCache[$property][$bId] ?? null;

				$aExists = $aValue !== null;
				$bExists = $bValue !== null;

				if ($aExists && $bExists) {
					$comparison = $natsort ? strnatcasecmp((string)$aValue, (string)$bValue) : $aValue <=> $bValue;
					if ($comparison !== 0) {
						return $reverse ? -$comparison : $comparison;
					}
				} elseif ($aExists) {
					return $reverse ? 1 : -1;
				} elseif ($bExists) {
					return $reverse ? -1 : 1;
				}
			}

			return 0;
		});

		// Clear cache to free memory
		$this->propertyCache = [];

		return $collection;
	}

	/**
	 * Preprocess and validate rules for better performance.
	 *
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	private function preprocessRules(array $rules): array
	{
		$processedRules = [];

		foreach ($rules as $rule) {
			if (!isset($rule['property'])) {
				continue;
			}

			$processedRules[] = [
				'property' => $rule['property'],
				'natural'  => isset($rule['natural']) && boolval($rule['natural']) === true,
				'reverse'  => isset($rule['reverse']) && boolval($rule['reverse']) === true,
			];
		}

		return $processedRules;
	}

	/**
	 * Extract all property values for all items to cache them.
	 *
	 * @param array<array<string,mixed>> $rules
	 */
	private function extractPropertyValues(array $rules): void
	{
		foreach ($rules as $rule) {
			$property = (string)$rule['property'];

			$this->propertyCache[$property] = [];

			foreach ($this->collection as $item) {
				$itemId = $this->getItemId($item);

				$this->propertyCache[$property][$itemId] = CollectionRefiner::getPropertyValueForRecord($item, $property);
			}
		}
	}

	/**
	 * Get a unique identifier for an item for caching.
	 *
	 * @param array<string,mixed> $item
	 */
	private function getItemId(array $item): string
	{
		// Use ID if available, otherwise use serialized content hash
		if (isset($item['id'])) {
			return (string)$item['id'];
		}

		// Use a hash for memory efficiency
		return md5(serialize($item));
	}
}

<?php

namespace TotalCMS\Utils;

use Illuminate\Support\Collection;

/**
 * Collection Sorter
 * sorts a collection of items.
 */
class CollectionSorter
{
	/**
	 * Constructor.
	 *
	 * @param array<array<string,mixed>> $collection
	 */
	public function __construct(
		private array $collection,
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
		$collection = $this->collection;

		usort($collection, function ($a, $b) use ($rules) {
			// reverse the rules so that the sort happens in logical order
			foreach (array_reverse($rules) as $rule) {
				if (isset($rule['shuffle']) && boolval($rule['shuffle']) === true) {
					return rand(-1, 1); // Randomize order if shuffle is true
				}
				if (!isset($rule['property'])) {
					continue;
				}
				$natsort = false;
				if (isset($rule['natural']) && boolval($rule['natural']) === true) {
					$natsort = true;
				}

				$property = $rule['property'];
				$aValue   = CollectionRefiner::getPropertyValueForRecord($a, $property);
				$bValue   = CollectionRefiner::getPropertyValueForRecord($b, $property);

				$aExists = $aValue !== null;
				$bExists = $bValue !== null;

				if ($aExists && $bExists) {
					$comparison = $natsort ? strnatcasecmp($aValue, $bValue) : $aValue <=> $bValue;
					if ($comparison !== 0) {
						return isset($rule['reverse']) && $rule['reverse'] ? -$comparison : $comparison;
					}
				} elseif ($aExists) {
					return isset($rule['reverse']) && $rule['reverse'] ? 1 : -1;
				} elseif ($bExists) {
					return isset($rule['reverse']) && $rule['reverse'] ? -1 : 1;
				}
			}

			return 0; // Neither contain key or all comparisons are equal
		});

		return $collection;
	}
}

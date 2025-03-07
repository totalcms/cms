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
     * @param array<array<string,mixed>> $rules
     *
     * @return array<array<string,mixed>>
     */
    // public function sortByRules(array $rules): array
    // {
    //     return $this->multiSort($this->collection, $rules);
    // }

    /**
     * Sort a collection by multiple criteria.
     *
     * @param array<array<string,mixed>> $collection
     * @param array<array<string,mixed>> $rules
     *
     * @return array<array<string,mixed>>
     */
    // protected function multiSort(array $collection, array $rules): array
    // {
    //     usort($collection, function ($a, $b) use ($rules) {
    //         foreach ($rules as $rule) {
    //             $property = $rule['property'];
    //             $aValue = CollectionRefiner::getPropertyValueForRecord($a, $property);
    //             $bValue = CollectionRefiner::getPropertyValueForRecord($b, $property);

    //             $aExists = $aValue !== null;
    //             $bExists = $bValue !== null;

    //             if ($aExists && $bExists) {
    //                 $comparison = $aValue <=> $bValue;
    //                 if ($comparison !== 0) {
    //                     return isset($rule['reverse']) && $rule['reverse'] ? -$comparison : $comparison;
    //                 }
    //             } elseif ($aExists) {
    //                 return isset($rule['reverse']) && $rule['reverse'] ? 1 : -1;
    //             } elseif ($bExists) {
    //                 return isset($rule['reverse']) && $rule['reverse'] ? -1 : 1;
    //             }
    //         }

    //         return 0; // Neither contain key or all comparisons are equal
    //     });

    //     return $collection;
    // }

	/**
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public function sortByRules(array $rules): array
	{
		$sortedCollection = $this->collection;

		foreach ($rules as $rule) {
			$sortedCollection = $this->sortByRule($sortedCollection, $rule);
		}

		return $sortedCollection;
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 * @param array<string,mixed> $rule
	 *
	 * @return array<array<string,mixed>>
	 */
	protected function sortByRule(array $collection, array $rule): array
	{
		if (isset($rule['shuffle']) && boolval($rule['shuffle']) === true) {
			return $this->shuffle();
		}
		if (!isset($rule['property'])) {
			return $collection;
		}
		$operator = 'sort';
		if (isset($rule['natural']) && boolval($rule['natural']) === true) {
			$operator = 'natsort';
		}
		$collection = self::$operator($collection, strval($rule['property']));

		if (isset($rule['reverse']) && boolval($rule['reverse']) === true) {
			$collection = array_reverse($collection);
		}

		return $collection;
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	protected static function natsort(array $collection, string $property): array
	{
		usort($collection, function ($a, $b) use ($property) {
			$aValue = CollectionRefiner::getPropertyValueForRecord($a, $property);
			$bValue = CollectionRefiner::getPropertyValueForRecord($b, $property);

			$aExists = $aValue !== null;
			$bExists = $bValue !== null;
			if ($aExists && $bExists) {
				return strnatcasecmp($aValue, $bValue);
			} elseif ($aExists) {
				return -1; // prioritize $a
			} elseif ($bExists) {
				return 1; // prioritize $b
			}

			return 0; // Niether contain key
		});

		return $collection;
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	protected static function sort(array $collection, string $property): array
	{
		usort($collection, function ($a, $b) use ($property) {
			$aValue = CollectionRefiner::getPropertyValueForRecord($a, $property);
			$bValue = CollectionRefiner::getPropertyValueForRecord($b, $property);

			$aExists = $aValue !== null;
			$bExists = $bValue !== null;
			if ($aExists && $bExists) {
				return $aValue <=> $bValue;
			} elseif ($aExists) {
				return -1; // prioritize $a
			} elseif ($bExists) {
				return 1; // prioritize $b
			}

			return 0; // Niether contain key
		});

		return $collection;
	}
}

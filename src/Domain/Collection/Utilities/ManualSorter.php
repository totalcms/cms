<?php

namespace TotalCMS\Domain\Collection\Utilities;

/**
 * Manual Sorter
 * Sorts a collection by explicit value order with remainder handling.
 */
class ManualSorter
{
	/**
	 * Constructor.
	 *
	 * @param array<array<string,mixed>> $collection
	 */
	public function __construct(
		private readonly array $collection,
	) {
	}

	/**
	 * Sort collection by explicit value order.
	 *
	 * @param array<string,mixed> $options Options: property, order, remainder, excludeRemainder
	 *
	 * @return array<array<string,mixed>>
	 */
	public function sort(array $options): array
	{
		$property         = $options['property'] ?? '';
		$order            = $options['order'] ?? [];
		$remainder        = $options['remainder'] ?? null;
		$excludeRemainder = $options['excludeRemainder'] ?? false;

		// Ensure order is an array
		if (!is_array($order)) {
			$order = [];
		}

		if ($property === '' || $order === []) {
			// No manual sort configured, just apply remainder sort if provided
			if ($remainder !== null && is_array($remainder)) {
				$sorter = new CollectionSorter($this->collection);

				return $sorter->sortByRules([$remainder]);
			}

			return $this->collection;
		}

		// Create order lookup map for O(1) access
		$orderMap   = array_flip($order);
		$orderCount = count($order);

		// Separate items into ordered and remainder groups
		$ordered   = [];
		$remaining = [];

		foreach ($this->collection as $item) {
			$value = CollectionRefiner::getPropertyValueForRecord($item, $property);

			if ($value !== null && isset($orderMap[$value])) {
				$position = $orderMap[$value];
				if (!isset($ordered[$position])) {
					$ordered[$position] = [];
				}
				$ordered[$position][] = $item;
			} else {
				$remaining[] = $item;
			}
		}

		// Sort items within each ordered position by remainder rules
		if ($remainder !== null && is_array($remainder)) {
			foreach ($ordered as $position => $items) {
				if (count($items) > 1) {
					$sorter             = new CollectionSorter($items);
					$ordered[$position] = $sorter->sortByRules([$remainder]);
				}
			}

			// Sort remaining items
			if ($remaining !== []) {
				$sorter    = new CollectionSorter($remaining);
				$remaining = $sorter->sortByRules([$remainder]);
			}
		}

		// Build result: ordered items first (in order), then remainder
		$result = [];
		for ($i = 0; $i < $orderCount; $i++) {
			if (isset($ordered[$i])) {
				foreach ($ordered[$i] as $item) {
					$result[] = $item;
				}
			}
		}

		// Append remainder unless excluded
		if (!$excludeRemainder) {
			foreach ($remaining as $item) {
				$result[] = $item;
			}
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Query\Service\ObjectFilter;

/**
 * Service for filtering DataView objects based on include/exclude criteria.
 *
 * Wraps ObjectFilter with DataView-loading capabilities via DataViewFetcher.
 * For filtering raw arrays without DataView loading, use ObjectFilter directly.
 *
 * DataView data is user-defined (via Twig templates) and may not always be
 * an array of objects. This service validates the data shape before filtering
 * and returns the raw data unchanged when it is not filterable.
 */
readonly class DataViewFilter
{
	public function __construct(
		private DataViewFetcher $dataViewFetcher,
		private ObjectFilter $objectFilter,
	) {
	}

	/**
	 * Fetch and filter DataView objects.
	 *
	 * Filters are only applied when the data is an array of objects
	 * (associative arrays). Flat arrays or other shapes are returned as-is.
	 *
	 * @param string               $viewId  DataView ID
	 * @param array<string,string> $options Filter options (include, exclude, sort)
	 *
	 * @return array<mixed>
	 */
	public function fetchFilteredViewData(string $viewId, array $options = []): array
	{
		$data = $this->dataViewFetcher->getViewData($viewId);

		if ($options === [] || !$this->isFilterable($data)) {
			return $data;
		}

		$data = $this->objectFilter->filterObjects($data, $options);

		/** @var array<int,array<string,mixed>> $data */
		return CollectionSorter::sortByProperty($data, $options['sort'] ?? '');
	}

	/**
	 * Get raw (unfiltered) DataView data.
	 *
	 * @return array<mixed>
	 */
	public function getViewData(string $viewId): array
	{
		return $this->dataViewFetcher->getViewData($viewId);
	}

	/**
	 * Check whether view data is an array of objects (associative arrays)
	 * that ObjectFilter can process.
	 *
	 * @param array<mixed> $data
	 */
	private function isFilterable(array $data): bool
	{
		if ($data === []) {
			return false;
		}

		// Check the first element — if it's an associative array the data is filterable
		$first = reset($data);

		return is_array($first) && !array_is_list($first);
	}
}

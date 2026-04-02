<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Query\Service\ObjectFilter;

/**
 * Service for filtering index objects based on include/exclude criteria.
 *
 * Wraps ObjectFilter with index-loading capabilities via IndexReader.
 * For filtering raw arrays without index loading, use ObjectFilter directly.
 */
readonly class IndexFilter
{
	public function __construct(
		private IndexReader $indexReader,
		private ObjectFilter $objectFilter,
	) {
	}

	/**
	 * Fetch and filter index objects for a collection.
	 *
	 * @param string               $collection Collection name
	 * @param array<string,string> $options    Filter options (include, exclude, sort)
	 *
	 * @return array<int,array<string,mixed>> Filtered array of objects
	 */
	public function fetchFilteredIndex(string $collection, array $options = []): array
	{
		$index = $this->indexReader->fetchIndex($collection);

		$items = $this->objectFilter->filterObjects($index->objects->all(), $options);

		return CollectionSorter::sortByProperty($items, $options['sort'] ?? '');
	}

	/**
	 * Fetch the full IndexData with filtered objects.
	 *
	 * @param string               $collection Collection name
	 * @param array<string,string> $options    Filter options (include, exclude, sort)
	 */
	public function fetchFilteredIndexData(string $collection, array $options = []): IndexData
	{
		$index = $this->indexReader->fetchIndex($collection);

		$filteredObjects = $this->objectFilter->filterObjects($index->objects->all(), $options);
		$filteredObjects = CollectionSorter::sortByProperty($filteredObjects, $options['sort'] ?? '');

		return new IndexData($filteredObjects);
	}

	/**
	 * Filter an array of objects based on include/exclude criteria.
	 *
	 * @param array<int,array<string,mixed>> $objects Array of objects to filter
	 * @param array<string,string>           $options Filter options (include, exclude)
	 *
	 * @return array<int,array<string,mixed>> Filtered array of objects
	 */
	public function filterObjects(array $objects, array $options): array
	{
		return $this->objectFilter->filterObjects($objects, $options);
	}

	/**
	 * Check if a single object matches the filter criteria.
	 *
	 * @param array<string,mixed>                        $object        The object to check
	 * @param array<string,string>|array<string,mixed> $filterOptions Filter options (include, exclude)
	 */
	public function matchesFilter(array $object, array $filterOptions): bool
	{
		return $this->objectFilter->matchesFilter($object, $filterOptions);
	}

	/**
	 * Extract filter options from options array.
	 *
	 * @param array<string,string> $options Options array
	 *
	 * @return array<string,string> Filter options (include, exclude)
	 */
	public function extractFilterOptions(array $options): array
	{
		return $this->objectFilter->extractFilterOptions($options);
	}

	/**
	 * Parse a filter string into field/value pairs.
	 *
	 * @param string $filterString Filter string (e.g., "field1:value1,field2:value2")
	 *
	 * @return array<int,array{field:string,value:mixed}> Array of field/value pairs
	 */
	public function parseFilterString(string $filterString): array
	{
		return $this->objectFilter->parseFilterString($filterString);
	}
}

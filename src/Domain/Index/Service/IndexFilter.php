<?php

namespace TotalCMS\Domain\Index\Service;

use TotalCMS\Domain\Index\Data\IndexData;

/**
 * Service for filtering index objects based on include/exclude criteria.
 *
 * Filter format:
 * - include: "field1:value1,field2:value2" - Object must match ALL conditions
 * - exclude: "field1:value1,field2:value2" - Object is excluded if it matches ANY condition
 *
 * Supported value formats:
 * - field:value - Matches specific value (or checks if value exists in array field)
 * - field:true - Matches boolean true (or string "true")
 * - field:false - Matches boolean false (or string "false")
 * - field - Defaults to field:true
 *
 * Array field support:
 * When a field contains an array, the filter checks if the value exists in the array using in_array().
 * Example: tags:travel matches if "travel" is in the tags array.
 *
 * Example usage:
 * ```php
 * $filter = new IndexFilter();
 * $filtered = $filter->filterObjects($objects, [
 *     'include' => 'published:true,tags:travel',
 *     'exclude' => 'draft:true,tags:archived'
 * ]);
 * ```
 */
readonly class IndexFilter
{
	public function __construct(
		private IndexReader $indexReader,
	) {
	}

	/**
	 * Fetch and filter index objects for a collection.
	 *
	 * @param string               $collection Collection name
	 * @param array<string,string> $options    Filter options (include, exclude)
	 *
	 * @return array<int,array<string,mixed>> Filtered array of objects
	 */
	public function fetchFilteredIndex(string $collection, array $options = []): array
	{
		$index = $this->indexReader->fetchIndex($collection);

		return $this->filterObjects($index->objects->all(), $options);
	}

	/**
	 * Fetch the full IndexData with filtered objects.
	 *
	 * @param string               $collection Collection name
	 * @param array<string,string> $options    Filter options (include, exclude)
	 */
	public function fetchFilteredIndexData(string $collection, array $options = []): IndexData
	{
		$index = $this->indexReader->fetchIndex($collection);

		$filteredObjects = $this->filterObjects($index->objects->all(), $options);

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
		// Extract filter options
		$filterOptions = $this->extractFilterOptions($options);

		// If no filters specified, return all objects
		if ($filterOptions === []) {
			return $objects;
		}

		// Filter objects
		return array_values(array_filter($objects, function ($object) use ($filterOptions) {
			return $this->matchesFilter($object, $filterOptions);
		}));
	}

	/**
	 * Check if a single object matches the filter criteria.
	 *
	 * @param array<string,mixed> $object        The object to check
	 * @param array<string,string>|array<string,mixed> $filterOptions Filter options (include, exclude)
	 */
	public function matchesFilter(array $object, array $filterOptions): bool
	{
		// If no filters are specified, include all objects
		if ($filterOptions === []) {
			return true;
		}

		// Check exclude filters first (early return if excluded)
		if (isset($filterOptions['exclude']) && $this->isExcluded($object, $filterOptions['exclude'])) {
			return false;
		}

		// Check include filters
		return !(isset($filterOptions['include']) && !$this->isIncluded($object, $filterOptions['include']));
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
		$filterOptions = [];

		// Extract filter parameters
		$filterKeys = ['include', 'exclude'];

		foreach ($filterKeys as $key) {
			if (isset($options[$key])) {
				$filterOptions[$key] = $options[$key];
			}
		}

		return $filterOptions;
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
		$filters = [];
		$fields  = explode(',', $filterString);

		foreach ($fields as $field) {
			$parts = explode(':', trim($field), 2);
			$fieldName = trim($parts[0]);
			$value     = count($parts) === 2 ? trim($parts[1]) : 'true'; // Default to 'true' if no value provided

			// Convert 'true'/'false' strings to boolean for comparison
			if ($value === 'true') {
				$value = true;
			} elseif ($value === 'false') {
				$value = false;
			}

			$filters[] = [
				'field' => $fieldName,
				'value' => $value,
			];
		}

		return $filters;
	}

	/**
	 * Check if an object should be excluded based on exclude filters.
	 *
	 * Returns true if the object matches ANY of the exclusion criteria.
	 *
	 * @param array<string,mixed> $object        The object to check
	 * @param string              $excludeString Exclude filter string
	 */
	private function isExcluded(array $object, string $excludeString): bool
	{
		$excludeFilters = $this->parseFilterString($excludeString);

		foreach ($excludeFilters as $filter) {
			if (!isset($object[$filter['field']])) {
				continue;
			}

			$fieldValue = $object[$filter['field']];

			// If field is an array, check if value exists in array
			if (is_array($fieldValue)) {
				if (in_array($filter['value'], $fieldValue, true)) {
					return true; // Value found in array
				}
			} elseif ($fieldValue === $filter['value']) {
				return true; // Object matches exclusion criteria
			}
		}

		return false; // Object doesn't match any exclusion criteria
	}

	/**
	 * Check if an object should be included based on include filters.
	 *
	 * Returns true only if the object matches ALL of the inclusion criteria.
	 *
	 * @param array<string,mixed> $object       The object to check
	 * @param string              $filterString Include filter string
	 */
	private function isIncluded(array $object, string $filterString): bool
	{
		$includeFilters = $this->parseFilterString($filterString);

		foreach ($includeFilters as $filter) {
			if (!isset($object[$filter['field']])) {
				return false; // Object doesn't have the field
			}

			$fieldValue = $object[$filter['field']];

			// If field is an array, check if value exists in array
			if (is_array($fieldValue)) {
				if (!in_array($filter['value'], $fieldValue, true)) {
					return false; // Value not found in array
				}
			} elseif ($fieldValue !== $filter['value']) {
				return false; // Object doesn't match this include criteria
			}
		}

		return true; // Object matches all include criteria
	}
}

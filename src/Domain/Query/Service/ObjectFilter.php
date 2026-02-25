<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Query\Service;

/**
 * Generic array-of-objects filtering with include/exclude criteria.
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
 * Comparison behavior:
 * - Boolean values: Fast strict comparison (===)
 * - String values: Case-insensitive comparison
 * - Array fields: Case-insensitive search for strings within array
 */
readonly class ObjectFilter
{
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
		$filterOptions = $this->extractFilterOptions($options);

		if ($filterOptions === []) {
			return $objects;
		}

		return array_values(array_filter($objects, fn (array $object): bool => $this->matchesFilter($object, $filterOptions)));
	}

	/**
	 * Check if a single object matches the filter criteria.
	 *
	 * @param array<string,mixed>                        $object        The object to check
	 * @param array<string,string>|array<string,mixed> $filterOptions Filter options (include, exclude)
	 */
	public function matchesFilter(array $object, array $filterOptions): bool
	{
		if ($filterOptions === []) {
			return true;
		}

		if (isset($filterOptions['exclude']) && $this->isExcluded($object, $filterOptions['exclude'])) {
			return false;
		}

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
			$parts     = explode(':', trim($field), 2);
			$fieldName = trim($parts[0]);
			$value     = count($parts) === 2 ? trim($parts[1]) : 'true';

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

			$fieldValue  = $object[$filter['field']];
			$filterValue = $filter['value'];

			if (is_bool($filterValue)) {
				if ($fieldValue === $filterValue) {
					return true;
				}
			} elseif (is_array($fieldValue)) {
				$lowerFilterValue = is_string($filterValue) ? strtolower($filterValue) : $filterValue;
				foreach ($fieldValue as $item) {
					if (is_string($item) && is_string($filterValue)) {
						if (strtolower($item) === $lowerFilterValue) {
							return true;
						}
					} elseif ($item === $filterValue) {
						return true;
					}
				}
			} elseif (is_string($fieldValue) && is_string($filterValue)) {
				if (strtolower($fieldValue) === strtolower($filterValue)) {
					return true;
				}
			} elseif ($fieldValue === $filterValue) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an object should be included based on include filters.
	 *
	 * @param array<string,mixed> $object       The object to check
	 * @param string              $filterString Include filter string
	 */
	private function isIncluded(array $object, string $filterString): bool
	{
		$includeFilters = $this->parseFilterString($filterString);

		foreach ($includeFilters as $filter) {
			if (!isset($object[$filter['field']])) {
				return false;
			}

			$fieldValue  = $object[$filter['field']];
			$filterValue = $filter['value'];

			if (is_bool($filterValue)) {
				if ($fieldValue !== $filterValue) {
					return false;
				}
			} elseif (is_array($fieldValue)) {
				$found            = false;
				$lowerFilterValue = is_string($filterValue) ? strtolower($filterValue) : $filterValue;
				foreach ($fieldValue as $item) {
					if (is_string($item) && is_string($filterValue)) {
						if (strtolower($item) === $lowerFilterValue) {
							$found = true;
							break;
						}
					} elseif ($item === $filterValue) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					return false;
				}
			} elseif (is_string($fieldValue) && is_string($filterValue)) {
				if (strtolower($fieldValue) !== strtolower($filterValue)) {
					return false;
				}
			} elseif ($fieldValue !== $filterValue) {
				return false;
			}
		}

		return true;
	}
}

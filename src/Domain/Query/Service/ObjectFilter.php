<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Query\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Generic array-of-objects filtering with include/exclude criteria.
 *
 * Filter format:
 * - include: "field1:value1,field2:value2" - Object must match ALL conditions
 * - exclude: "field1:value1,field2:value2" - Object is excluded if it matches ANY condition
 *
 * Supported value formats:
 * - field:value         - eq match (exact/case-insensitive for strings, boolean coercion for true/false)
 * - field:op:value      - operator-qualified match (see operators below)
 * - field:true          - Matches boolean true (or string "true")
 * - field:false         - Matches boolean false (or string "false")
 * - field               - Defaults to field:true
 *
 * Supported operators (field:op:value syntax):
 * - eq      - equal (case-insensitive for strings; default when no op given)
 * - ne      - not equal
 * - lt      - numeric less-than
 * - lte     - numeric less-than-or-equal
 * - gt      - numeric greater-than
 * - gte     - numeric greater-than-or-equal
 * - contains - case-insensitive substring match
 * - starts  - case-insensitive prefix match
 * - ends    - case-insensitive suffix match
 * - in      - field value is in a pipe-separated list (case-sensitive equality): field:in:a|b|c
 * - notin   - inverse of in: field:notin:a|b|c
 *
 * Comparison behavior:
 * - Boolean values: Fast strict comparison (===)
 * - String values: Case-insensitive comparison (for eq/ne/contains/starts/ends)
 * - Array fields: Case-insensitive search for strings within array (eq operator only)
 * - Wildcard patterns: *value* (contains), value* (starts with), *value (ends with) — eq only
 */
readonly class ObjectFilter
{
	/**
	 * Whether a form-field type (the `field` key on a schema property) has
	 * meaningful include/exclude filter semantics. Defers to SchemaData's
	 * FILTERABLE_FIELD_TYPES catalog so the answer is consistent across every
	 * consumer (MCP description-catalog builder, admin search UI, REST query
	 * validators).
	 *
	 * Per-property operator overrides (e.g. `mcp.filterable: false`) are
	 * applied at the consumer layer — this method only reports the default.
	 */
	public static function isFilterableType(string $fieldType): bool
	{
		return in_array($fieldType, SchemaData::FILTERABLE_FIELD_TYPES, true);
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

		return !isset($filterOptions['include']) || $this->isIncluded($object, $filterOptions['include']);
	}

	/**
	 * Extract filter options from options array.
	 *
	 * Empty-string values are treated as "filter not set" — an empty include
	 * or exclude string would otherwise be parsed as a filter for a field
	 * with empty name, which never matches anything and silently drops every
	 * result. Callers wanting "no filter" can pass either an absent key or
	 * an empty string and get consistent behavior.
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
			if (isset($options[$key]) && trim($options[$key]) !== '') {
				$filterOptions[$key] = $options[$key];
			}
		}

		return $filterOptions;
	}

	/** Operators that use the field:op:value three-part syntax. */
	private const NAMED_OPERATORS = [
		'eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'contains', 'starts', 'ends', 'in', 'notin',
	];

	/**
	 * Parse a filter string into field/operator/value tuples.
	 *
	 * Three-part format:  field:op:value  (e.g. "price:lte:500000", "city:contains:austin")
	 * Two-part format:    field:value     (e.g. "status:active") — implies op=eq
	 * One-part format:    field           — implies op=eq, value=true
	 *
	 * Clauses are separated by `,`. List values for `in`/`notin` use `|` as the
	 * item separator (e.g. "category:in:tech|travel|food"), so `,` is unambiguously
	 * a clause boundary.
	 *
	 * @param string $filterString Filter string (e.g., "field1:value1,field2:op:value2")
	 *
	 * @return array<int,array{field:string,op:string,value:mixed}> Array of field/op/value tuples
	 */
	public function parseFilterString(string $filterString): array
	{
		$filters = [];

		foreach (explode(',', $filterString) as $clause) {
			$clause    = trim($clause);
			$parts     = explode(':', $clause, 3);
			$fieldName = trim($parts[0]);

			if ($fieldName === '') {
				continue;
			}

			// Three-part: field:op:value — but only when the middle token is a known operator.
			if (count($parts) === 3 && in_array(trim($parts[1]), self::NAMED_OPERATORS, true)) {
				$op    = trim($parts[1]);
				$value = trim($parts[2]);
			} elseif (count($parts) >= 2) {
				// Two-part (or three-part with unknown middle token): field:value.
				// Reassemble from part 1 onward so colons in the value are preserved.
				$op    = 'eq';
				$value = trim(implode(':', array_slice($parts, 1)));
			} else {
				// One-part: bare field name — defaults to field:true.
				$op    = 'eq';
				$value = 'true';
			}

			// Coerce boolean literals for eq/ne only; other operators work on strings/numbers.
			if (in_array($op, ['eq', 'ne'], true)) {
				if ($value === 'true') {
					$value = true;
				} elseif ($value === 'false') {
					$value = false;
				}
			}

			$filters[] = [
				'field' => $fieldName,
				'op'    => $op,
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
			$op          = $filter['op'];
			$filterValue = $filter['value'];
			$fieldValue  = $object[$filter['field']] ?? null;

			if ($op !== 'eq' && $op !== 'ne') {
				// Non-eq operators: missing field = no match (not excluded).
				if ($fieldValue === null) {
					continue;
				}

				if ($this->applyOperator($op, $fieldValue, $filterValue)) {
					return true;
				}

				continue;
			}

			// eq operator (legacy path, unchanged behaviour).
			if (!isset($object[$filter['field']])) {
				continue;
			}

			$fieldValue = $object[$filter['field']];

			if ($op === 'ne') {
				// ne: excluded when field does NOT equal the value.
				if (!$this->valuesEqual($fieldValue, $filterValue)) {
					return true;
				}
			} elseif (is_bool($filterValue)) {
				if ($fieldValue === $filterValue) {
					return true;
				}
			} elseif (is_array($fieldValue)) {
				foreach ($fieldValue as $item) {
					if (is_string($item) && is_string($filterValue)) {
						if ($this->matchesString($item, $filterValue)) {
							return true;
						}
					} elseif ($this->matchesNumeric($item, $filterValue)) {
						return true;
					} elseif ($item === $filterValue) {
						return true;
					}
				}
			} elseif (is_string($fieldValue) && is_string($filterValue)) {
				if ($this->matchesString($fieldValue, $filterValue)) {
					return true;
				}
			} elseif ($this->matchesNumeric($fieldValue, $filterValue)) {
				return true;
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
			$op          = $filter['op'];
			$filterValue = $filter['value'];
			$fieldValue  = $object[$filter['field']] ?? null;

			if ($op !== 'eq' && $op !== 'ne') {
				// Non-eq operators: missing field = no match.
				if ($fieldValue === null) {
					return false;
				}

				if (!$this->applyOperator($op, $fieldValue, $filterValue)) {
					return false;
				}

				continue;
			}

			// eq / ne — missing field disqualifies for inclusion.
			if (!isset($object[$filter['field']])) {
				return false;
			}

			$fieldValue = $object[$filter['field']];

			if ($op === 'ne') {
				// ne: included only when field does NOT equal the value.
				if ($this->valuesEqual($fieldValue, $filterValue)) {
					return false;
				}
			} elseif (is_bool($filterValue)) {
				if ($fieldValue !== $filterValue) {
					return false;
				}
			} elseif (is_array($fieldValue)) {
				$found = false;
				foreach ($fieldValue as $item) {
					if (is_string($item) && is_string($filterValue)) {
						if ($this->matchesString($item, $filterValue)) {
							$found = true;
							break;
						}
					} elseif ($this->matchesNumeric($item, $filterValue)) {
						$found = true;
						break;
					} elseif ($item === $filterValue) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					return false;
				}
			} elseif (is_string($fieldValue) && is_string($filterValue)) {
				if (!$this->matchesString($fieldValue, $filterValue)) {
					return false;
				}
			} elseif ($this->matchesNumeric($fieldValue, $filterValue)) {
				// Numeric match — handled above
			} elseif ($fieldValue !== $filterValue) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply a non-eq operator to a field value and filter value.
	 * Returns true if the object's field passes the operator test.
	 *
	 * Operators handled: ne, lt, lte, gt, gte, contains, starts, ends, in, notin.
	 * (ne is also handled inline in isIncluded/isExcluded but routed here from
	 * the non-eq fast path for future uniformity.)
	 */
	private function applyOperator(string $op, mixed $fieldValue, mixed $filterValue): bool
	{
		return match ($op) {
			'lt', 'lte', 'gt', 'gte'  => $this->applyNumericOp($op, $fieldValue, $filterValue),
			'contains'                => $this->applyContains($fieldValue, (string)$filterValue),
			'starts'                  => $this->applyStarts($fieldValue, (string)$filterValue),
			'ends'                    => $this->applyEnds($fieldValue, (string)$filterValue),
			'in'                      => $this->applyIn($fieldValue, $filterValue),
			'notin'                   => !$this->applyIn($fieldValue, $filterValue),
			default                   => false,
		};
	}

	/**
	 * Apply a numeric comparison operator (lt, lte, gt, gte).
	 * Both sides must be numeric; if either is not, returns false.
	 */
	private function applyNumericOp(string $op, mixed $fieldValue, mixed $filterValue): bool
	{
		if (!is_numeric($fieldValue) || !is_numeric($filterValue)) {
			return false;
		}

		$fv = (float)$fieldValue;
		$cv = (float)$filterValue;

		return match ($op) {
			'lt'    => $fv < $cv,
			'lte'   => $fv <= $cv,
			'gt'    => $fv > $cv,
			'gte'   => $fv >= $cv,
			default => false,
		};
	}

	/**
	 * Case-insensitive substring match (contains operator).
	 * Field value is cast to string for non-string scalars.
	 */
	private function applyContains(mixed $fieldValue, string $needle): bool
	{
		if (!is_scalar($fieldValue)) {
			return false;
		}

		return str_contains(strtolower((string)$fieldValue), strtolower($needle));
	}

	/**
	 * Case-insensitive prefix match (starts operator).
	 */
	private function applyStarts(mixed $fieldValue, string $prefix): bool
	{
		if (!is_scalar($fieldValue)) {
			return false;
		}

		return str_starts_with(strtolower((string)$fieldValue), strtolower($prefix));
	}

	/**
	 * Case-insensitive suffix match (ends operator).
	 */
	private function applyEnds(mixed $fieldValue, string $suffix): bool
	{
		if (!is_scalar($fieldValue)) {
			return false;
		}

		return str_ends_with(strtolower((string)$fieldValue), strtolower($suffix));
	}

	/**
	 * List-membership check (in operator).
	 * filterValue is a pipe-separated string ("a|b|c") or a pre-split array.
	 * Field value must equal at least one element (case-sensitive).
	 */
	private function applyIn(mixed $fieldValue, mixed $filterValue): bool
	{
		if (!is_scalar($fieldValue)) {
			return false;
		}

		if (is_array($filterValue)) {
			$list = array_map(fn (mixed $v): string => (string)$v, $filterValue);
		} else {
			$list = array_map(trim(...), explode('|', (string)$filterValue));
		}

		return in_array((string)$fieldValue, $list, true);
	}

	/**
	 * Equality test shared between eq and ne operators.
	 * Mirrors the existing comparison semantics: boolean strict, numeric coerced, string case-insensitive.
	 */
	private function valuesEqual(mixed $fieldValue, mixed $filterValue): bool
	{
		if (is_bool($filterValue)) {
			return $fieldValue === $filterValue;
		}

		if (is_string($fieldValue) && is_string($filterValue)) {
			return strtolower($fieldValue) === strtolower($filterValue);
		}

		if (is_numeric($fieldValue) && is_numeric($filterValue)) {
			return (float)$fieldValue === (float)$filterValue;
		}

		return $fieldValue === $filterValue;
	}

	/**
	 * Match when one side is numeric and the other is a numeric string.
	 * Handles cases like field value int 2 vs filter string "2".
	 */
	private function matchesNumeric(mixed $fieldValue, mixed $filterValue): bool
	{
		return is_numeric($fieldValue) && is_numeric($filterValue)
			&& (string)$fieldValue === (string)$filterValue;
	}

	/**
	 * Match a string value against a filter pattern.
	 *
	 * Supports wildcard patterns:
	 * - *value* — contains "value"
	 * - value* — starts with "value"
	 * - *value — ends with "value"
	 * - value  — exact match (case-insensitive)
	 */
	private function matchesString(string $fieldValue, string $filterValue): bool
	{
		$startWild = str_starts_with($filterValue, '*');
		$endWild   = str_ends_with($filterValue, '*');

		if ($startWild && $endWild && strlen($filterValue) > 2) {
			// *value* — contains
			$needle = strtolower(substr($filterValue, 1, -1));

			return str_contains(strtolower($fieldValue), $needle);
		}

		if ($startWild) {
			// *value — ends with
			$needle = strtolower(substr($filterValue, 1));

			return str_ends_with(strtolower($fieldValue), $needle);
		}

		if ($endWild) {
			// value* — starts with
			$needle = strtolower(substr($filterValue, 0, -1));

			return str_starts_with(strtolower($fieldValue), $needle);
		}

		// Exact match (case-insensitive)
		return strtolower($fieldValue) === strtolower($filterValue);
	}
}

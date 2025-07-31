<?php

namespace TotalCMS\Domain\Collection\Utilities;

/**
 * Collection Refiner
 * Filters a collection of items.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class CollectionRefiner
{
	/** @var array<string,bool> */
	private static array $methodCache = [];

	/** @var array<string,int> */
	private static array $paramCountCache = [];

	/**
	 * Constructor.
	 *
	 * @param array<array<string,mixed>> $collection
	 */
	public function __construct(
		private array $collection,
	) {
	}

	/**
	 * Filters the collection by the rules.
	 *
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filter(array $rules): array
	{
		// Early exit for common cases
		if (empty($rules) || empty($this->collection)) {
			return $this->collection;
		}

		$filteredCollection = $this->collection;

		foreach ($rules as $rule) {
			// Early exit if nothing left to filter
			if (empty($filteredCollection)) {
				return [];
			}

			if (!isset($rule['property'], $rule['operator'])) {
				// Skip invalid rules
				continue;
			}

			$value = $rule['value'] ?? '';
			if (is_array($value)) {
				$filteredCollection = $this->filterByArrayRule(
					collection : $filteredCollection,
					property   : $rule['property'],
					values     : $value,
					operator   : $rule['operator'],
					logic      : $rule['logic'] ?? 'or',
				);
				continue;
			}

			$filteredCollection = $this->filterByRule(
				collection : $filteredCollection,
				property   : $rule['property'],
				value      : strval($value),
				operator   : $rule['operator'],
			);
		}

		return $filteredCollection;
	}

	/**
	 * Filter collection by array of values with OR/AND logic.
	 *
	 * @param array<array<string,mixed>> $collection The collection to filter
	 * @param string $property The property to filter on
	 * @param array<string> $values Array of values to match against
	 * @param string $operator The comparison operator (equal, contains, etc.)
	 * @param string $logic Logic operator: 'or' (default) returns items matching ANY value, 'and' returns items matching ALL values
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filterByArrayRule(array $collection, string $property, array $values = [], string $operator = 'equal', string $logic = 'or'): array
	{
		// Early exit for empty values
		if (empty($values)) {
			return $collection;
		}

		if ($logic === 'and') {
			return $this->filterByArrayRuleAnd($collection, $property, $values, $operator);
		}

		// Default OR logic (existing behavior)
		return $this->filterByArrayRuleOr($collection, $property, $values, $operator);
	}

	/**
	 * OR Logic: Return items that match ANY of the values.
	 *
	 * @param array<array<string,mixed>> $collection
	 * @param array<string> $values
	 *
	 * @return array<array<string,mixed>>
	 */
	private function filterByArrayRuleOr(array $collection, string $property, array $values, string $operator): array
	{
		// Use array to track unique items instead of array_merge
		$results = [];
		$seen    = [];

		foreach ($values as $value) {
			$filtered = $this->filterByRule(
				collection : $collection,
				property   : $property,
				value      : strval($value),
				operator   : $operator,
			);

			foreach ($filtered as $item) {
				// Use ID if available, otherwise serialize
				$key = isset($item['id']) ? $item['id'] : serialize($item);
				if (!isset($seen[$key])) {
					$results[]  = $item;
					$seen[$key] = true;
				}
			}
		}

		return $results;
	}

	/**
	 * AND Logic: Return items that match ALL of the values.
	 *
	 * @param array<array<string,mixed>> $collection
	 * @param array<string> $values
	 *
	 * @return array<array<string,mixed>>
	 */
	private function filterByArrayRuleAnd(array $collection, string $property, array $values, string $operator): array
	{
		$filteredCollection = $collection;

		// Apply each filter sequentially, narrowing down the results
		foreach ($values as $value) {
			// Early exit if nothing left to filter
			if (empty($filteredCollection)) {
				return [];
			}

			$filteredCollection = $this->filterByRule(
				collection : $filteredCollection,
				property   : $property,
				value      : strval($value),
				operator   : $operator,
			);
		}

		return $filteredCollection;
	}

	/**
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filterUnique(array $collection): array
	{
		$unique = [];
		$seen   = [];

		foreach ($collection as $item) {
			// Use ID if available, otherwise serialize
			$key = isset($item['id']) ? $item['id'] : serialize($item);
			if (!isset($seen[$key])) {
				$unique[]   = $item;
				$seen[$key] = true;
			}
		}

		return $unique;
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 *
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filterByRule(array $collection, string $property, string $value = '', string $operator = 'equal'): array
	{
		// If rule is prepended by not-, then invert the result
		$not = false;
		if (self::starts($operator, 'not-')) {
			$not      = true;
			$operator = mb_substr($operator, 4);
		}
		// If value is prepended by !, then invert the result
		if (self::starts($value, '!')) {
			$not   = true;
			$value = mb_substr($value, 1);
		}

		// Cache reflection results to avoid repeated reflection calls
		if (!isset(self::$paramCountCache[$operator])) {
			if (method_exists($this, $operator)) {
				$reflection                       = new \ReflectionMethod(CollectionRefiner::class, $operator);
				self::$paramCountCache[$operator] = $reflection->getNumberOfParameters();
				self::$methodCache[$operator]     = true;
			} else {
				self::$methodCache[$operator]     = false;
				self::$paramCountCache[$operator] = 2; // Default for fallback
			}
		}

		$numParams    = self::$paramCountCache[$operator];
		$methodExists = self::$methodCache[$operator];

		// If operator requires a value and it's empty, return all records
		if ($numParams === 2 && $value === '') {
			return $collection;
		}

		return array_filter($collection, function ($record) use ($property, $value, $operator, $not, $numParams, $methodExists) {
			$item = self::getPropertyValueForRecord($record, $property);

			if ($item === null) {
				return false;
			}

			if ($methodExists) {
				if (is_array($item)) {
					$found = self::filterArrayByRule($item, $value, $operator);

					return $not ? !$found : $found;
				}

				switch ($numParams) {
					case 1:
						$found = self::$operator($item);
						break;
					case 2:
						$found = self::$operator($item, $value);
						break;
					default:
						$found = false;
				}

				return $not ? !$found : $found;
			}

			return $record[$property] == $value;
		});
	}

	// private static function isAssociativeArray(array $array): bool {
	// 	return array_keys($array) !== range(0, count($array) - 1);
	// }

	/** @param array<mixed> $array */
	private static function isIndexedArray(array $array): bool
	{
		return array_keys($array) === range(0, count($array) - 1);
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param array<string,mixed> $record
	 */
	public static function getPropertyValueForRecord(array $record, string $property): mixed
	{
		// Fast path for direct properties (most common case)
		if (isset($record[$property])) {
			return $record[$property];
		}

		// Only do complex parsing if needed
		if (!str_contains($property, '.')) {
			return null;
		}

		// Handle nested properties
		$properties = explode('.', $property);
		$value      = $record;
		foreach ($properties as $prop) {
			if (is_array($value) && array_key_exists($prop, $value)) {
				$value = $value[$prop];

				if (is_array($value) && self::isIndexedArray($value)) {
					// Set $value to the first item in the array
					$value = $value[0] ?? null;
				}
			} else {
				return null;
			}
		}

		return $value;
	}

	/** @param array<int,mixed> $items */
	protected static function filterArrayByRule(array $items, mixed $value, string $operator): bool
	{
		// Use array_filter for better performance and early exit
		foreach ($items as $item) {
			if (self::$operator($item, $value)) {
				return true;
			}
		}

		return false;
	}

	protected static function equal(mixed $haystack, mixed $needle): bool
	{
		// Use loose comparison to handle string/number conversions
		return $haystack == $needle;
	}

	protected static function contains(string $haystack, string $needle): bool
	{
		return mb_strpos($haystack, $needle) !== false;
	}

	protected static function starts(string $haystack, string $needle): bool
	{
		return mb_strpos($haystack, $needle) === 0;
	}

	protected static function ends(string $haystack, string $needle): bool
	{
		$length = mb_strlen($needle);

		return $length > 0 ? mb_substr($haystack, -$length) === $needle : true;
	}

	protected static function like(string $haystack, string $regex): bool
	{
		return preg_match("/$regex/", $haystack) === 1;
	}

	protected static function equalCaseInsensitive(string $haystack, string $needle): bool
	{
		return mb_strtolower($haystack) === mb_strtolower($needle);
	}

	protected static function containsCaseInsensitive(string $haystack, string $needle): bool
	{
		// Use mb_stripos for better performance
		return mb_stripos($haystack, $needle) !== false;
	}

	protected static function startsCaseInsensitive(string $haystack, string $needle): bool
	{
		// Use mb_stripos for better performance
		return mb_stripos($haystack, $needle) === 0;
	}

	protected static function endsCaseInsensitive(string $haystack, string $needle): bool
	{
		$length = mb_strlen($needle);

		if ($length === 0) {
			return true;
		}

		// More efficient case-insensitive comparison
		return mb_strtolower(mb_substr($haystack, -$length)) === mb_strtolower($needle);
	}

	protected static function less(string $haystack, string $needle): bool
	{
		return $haystack < $needle;
	}

	protected static function lesseq(string $haystack, string $needle): bool
	{
		return $haystack <= $needle;
	}

	protected static function greater(string $haystack, string $needle): bool
	{
		return $haystack > $needle;
	}

	protected static function greatereq(string $haystack, string $needle): bool
	{
		return $haystack >= $needle;
	}

	/** @SuppressWarnings("PHPMD.ShortMethodName") */
	protected static function lt(string $haystack, string $needle): bool
	{
		return self::less($haystack, $needle);
	}

	/** @SuppressWarnings("PHPMD.ShortMethodName") */
	protected static function le(string $haystack, string $needle): bool
	{
		return self::lesseq($haystack, $needle);
	}

	/** @SuppressWarnings("PHPMD.ShortMethodName") */
	protected static function gt(string $haystack, string $needle): bool
	{
		return self::greater($haystack, $needle);
	}

	/** @SuppressWarnings("PHPMD.ShortMethodName") */
	protected static function ge(string $haystack, string $needle): bool
	{
		return self::greatereq($haystack, $needle);
	}

	/** @param string|int|bool $haystack */
	protected static function istrue(mixed $haystack): bool
	{
		return $haystack === true || $haystack === 'true' || $haystack === '1' || $haystack === 1;
	}

	/** @param string|int|bool $haystack */
	protected static function isfalse(mixed $haystack): bool
	{
		return $haystack === false || $haystack === 'false' || $haystack === '0' || $haystack === 0;
	}

	protected static function isempty(mixed $haystack): bool
	{
		return empty($haystack);
	}

	protected static function isnotempty(mixed $haystack): bool
	{
		return !empty($haystack);
	}

	protected static function pastToday(string $date): bool
	{
		return self::past($date) || self::today($date);
	}

	protected static function futureToday(string $date): bool
	{
		return self::future($date) || self::today($date);
	}

	protected static function past(string $date): bool
	{
		return strtotime($date) < time();
	}

	protected static function future(string $date): bool
	{
		return strtotime($date) > time();
	}

	protected static function today(string $date): bool
	{
		$time = strtotime($date);

		return $time >= strtotime('today') && $time < strtotime('tomorrow');
	}

	protected static function after(string $date, string $dateAfter): bool
	{
		return strtotime($date) > strtotime($dateAfter);
	}

	protected static function before(string $date, string $dateBefore): bool
	{
		return strtotime($date) < strtotime($dateBefore);
	}
}

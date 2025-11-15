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
		private readonly array $collection,
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
		if ($rules === [] || $this->collection === []) {
			return $this->collection;
		}

		$filteredCollection = $this->collection;

		foreach ($rules as $rule) {
			// Early exit if nothing left to filter
			if ($filteredCollection === []) {
				return [];
			}

			if (!isset($rule['property'])) {
				// Skip invalid rules
				continue;
			}

			$value = $rule['value'] ?? '';
			if (is_array($value)) {
				$filteredCollection = $this->filterByArrayRule(
					collection : $filteredCollection,
					property   : $rule['property'],
					values     : $value,
					operator   : $rule['operator'] ?? 'equal',
					logic      : $rule['logic'] ?? 'or',
				);
				continue;
			}

			$filteredCollection = $this->filterByRule(
				collection  : $filteredCollection,
				property    : $rule['property'],
				filterValue : strval($value),
				operator    : $rule['operator'] ?? 'equal',
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
		if ($values === []) {
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
				collection  : $collection,
				property    : $property,
				filterValue : strval($value),
				operator    : $operator,
			);

			foreach ($filtered as $item) {
				// Use ID if available, otherwise serialize
				$key = $item['id'] ?? serialize($item);
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
			if ($filteredCollection === []) {
				return [];
			}

			$filteredCollection = $this->filterByRule(
				collection  : $filteredCollection,
				property    : $property,
				filterValue : strval($value),
				operator    : $operator,
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
			$key = $item['id'] ?? serialize($item);
			if (!isset($seen[$key])) {
				$unique[]   = $item;
				$seen[$key] = true;
			}
		}

		return $unique;
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filterByRule(array $collection, string $property, string $filterValue = '', string $operator = 'equal'): array
	{
		// If rule is prepended by not-, then invert the result
		$not = false;
		if (self::starts($operator, 'not-')) {
			$not      = true;
			$operator = mb_substr($operator, 4);
		}
		// If value is prepended by !, then invert the result
		if (self::starts($filterValue, '!')) {
			$not         = true;
			$filterValue = mb_substr($filterValue, 1);
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
		if ($numParams === 2 && $filterValue === '') {
			return $collection;
		}

		return array_filter($collection, function (array $record) use ($property, $filterValue, $operator, $not, $numParams, $methodExists) {
			$propertyValue = self::getPropertyValueForRecord($record, $property);

			if ($propertyValue === null) {
				return false;
			}

			if ($methodExists) {
				if (is_array($propertyValue)) {
					$found = self::filterArrayByRule($propertyValue, $filterValue, $operator);

					return $not ? !$found : $found;
				}

				$found = match ($numParams) {
					1       => self::$operator($propertyValue),
					2       => self::$operator($propertyValue, $filterValue),
					default => false,
				};

				return $not ? !$found : $found;
			}

			return $record[$property] == $filterValue;
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

	/**
	 * Check if date is today or within N days in the future.
	 * Example: If today is Jan 15 and days=3, matches Jan 15, 16, 17, 18.
	 */
	protected static function todayPlusDays(string $date, int|string $days): bool
	{
		$time       = strtotime($date);
		$todayStart = strtotime('today');
		$rangeEnd   = strtotime("+$days days", $todayStart);

		// Date must be >= today's start AND < (today + days + 1 day)
		return $time >= $todayStart && $time < strtotime('+1 day', (int)$rangeEnd);
	}

	/**
	 * Check if date is today or within N days in the past.
	 * Example: If today is Jan 15 and days=3, matches Jan 12, 13, 14, 15.
	 */
	protected static function todayMinusDays(string $date, int|string $days): bool
	{
		$time       = strtotime($date);
		$todayEnd   = strtotime('tomorrow'); // Start of tomorrow = end of today range
		$rangeStart = strtotime("-$days days", strtotime('today'));

		// Date must be >= (today - days) AND < tomorrow
		return $time >= $rangeStart && $time < $todayEnd;
	}

	protected static function after(string $date, string $dateAfter): bool
	{
		return strtotime($date) > strtotime($dateAfter);
	}

	protected static function before(string $date, string $dateBefore): bool
	{
		return strtotime($date) < strtotime($dateBefore);
	}

	// ---------------------------------------------------------------------------------
	// Numeric Range Filtering
	// ---------------------------------------------------------------------------------

	/**
	 * Check if value is between two numbers (inclusive).
	 * Usage: {price: {operator: 'between', value: '10,100'}}.
	 */
	protected static function between(string $value, string $range): bool
	{
		$parts = array_map('trim', explode(',', $range));
		if (count($parts) !== 2) {
			return false;
		}

		[$min, $max] = $parts;
		$num         = (float)$value;

		return $num >= (float)$min && $num <= (float)$max;
	}

	// ---------------------------------------------------------------------------------
	// Calendar Period Filtering
	// ---------------------------------------------------------------------------------

	/**
	 * Check if date is in current week (Monday-Sunday).
	 */
	protected static function thisWeek(string $date): bool
	{
		$time      = strtotime($date);
		$weekStart = strtotime('monday this week');
		$weekEnd   = strtotime('sunday this week 23:59:59');

		return $time >= $weekStart && $time <= $weekEnd;
	}

	/**
	 * Check if date is in current month.
	 */
	protected static function thisMonth(string $date): bool
	{
		$time       = strtotime($date);
		$monthStart = strtotime('first day of this month');
		$monthEnd   = strtotime('last day of this month 23:59:59');

		return $time >= $monthStart && $time <= $monthEnd;
	}

	/**
	 * Check if date is in current year.
	 */
	protected static function thisYear(string $date): bool
	{
		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return false;
		}

		return date('Y', $timestamp) === date('Y');
	}

	// ---------------------------------------------------------------------------------
	// Text Length Filtering
	// ---------------------------------------------------------------------------------

	/**
	 * Check if text is longer than N characters.
	 * Usage: {summary: {operator: 'longerThan', value: 100}}.
	 */
	protected static function longerThan(string $text, int|string $length): bool
	{
		return mb_strlen($text) > (int)$length;
	}

	/**
	 * Check if text is shorter than N characters.
	 * Usage: {summary: {operator: 'shorterThan', value: 50}}.
	 */
	protected static function shorterThan(string $text, int|string $length): bool
	{
		return mb_strlen($text) < (int)$length;
	}

	// ---------------------------------------------------------------------------------
	// Array Counting
	// ---------------------------------------------------------------------------------

	/**
	 * Check if array has at least N items.
	 * Usage: {tags: {operator: 'hasMin', value: 3}}.
	 *
	 * @param array<mixed>|string $value
	 */
	protected static function hasMin(array|string $value, int|string $min): bool
	{
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			$value   = is_array($decoded) ? $decoded : [];
		}

		return count($value) >= (int)$min;
	}

	/**
	 * Check if array has at most N items.
	 * Usage: {tags: {operator: 'hasMax', value: 5}}.
	 *
	 * @param array<mixed>|string $value
	 */
	protected static function hasMax(array|string $value, int|string $max): bool
	{
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			$value   = is_array($decoded) ? $decoded : [];
		}

		return count($value) <= (int)$max;
	}

	/**
	 * Check if array has exactly N items.
	 * Usage: {tags: {operator: 'hasCount', value: 3}}.
	 *
	 * @param array<mixed>|string $value
	 */
	protected static function hasCount(array|string $value, int|string $count): bool
	{
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			$value   = is_array($decoded) ? $decoded : [];
		}

		return count($value) === (int)$count;
	}

	// ---------------------------------------------------------------------------------
	// Day-of-Week Filtering
	// ---------------------------------------------------------------------------------

	/**
	 * Check if date is a weekday (Monday-Friday).
	 */
	protected static function isWeekday(string $date): bool
	{
		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return false;
		}
		$dayNum = (int)date('N', $timestamp);

		return $dayNum <= 5;
	}

	/**
	 * Check if date is a weekend (Saturday-Sunday).
	 */
	protected static function isWeekend(string $date): bool
	{
		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return false;
		}
		$dayNum = (int)date('N', $timestamp);

		return $dayNum >= 6;
	}

	/**
	 * Check if date is on a specific day of week.
	 * Usage: {date: {operator: 'dayOfWeek', value: 'Monday'}}
	 * Or: {date: {operator: 'dayOfWeek', value: '1'}} (1=Mon, 7=Sun).
	 */
	protected static function dayOfWeek(string $date, int|string $day): bool
	{
		$dayMap = [
			'monday'    => 1,
			'tuesday'   => 2,
			'wednesday' => 3,
			'thursday'  => 4,
			'friday'    => 5,
			'saturday'  => 6,
			'sunday'    => 7,
		];

		$targetDay = is_numeric($day) ? (int)$day : ($dayMap[strtolower($day)] ?? 0);

		if ($targetDay === 0) {
			return false;
		}

		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return false;
		}

		return (int)date('N', $timestamp) === $targetDay;
	}
}

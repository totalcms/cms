<?php

namespace TotalCMS\Utils;

/**
 * Collection Refiner
 * Filters a collection of items.
 */
class CollectionRefiner
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

	/**
	 * Filters the collection by the rules.
	 *
	 * @param array<array<string,mixed>> $rules
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filter(array $rules): array
	{
		$filteredCollection = $this->collection;

		foreach ($rules as $rule) {
			$filteredCollection = $this->filterByRule(
				collection: $filteredCollection,
				property: $rule['property'],
				value: $rule['value'],
				operator: $rule['operator'],
			);
		}

		return $filteredCollection;
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @param array<array<string,mixed>> $collection
	 *
	 * @return array<array<string,mixed>>
	 */
	public function filterByRule(array $collection, string $property, string $value, string $operator = 'equal'): array
	{
		$reflection = new \ReflectionMethod(CollectionRefiner::class, "$operator");
		$numParams  = $reflection->getNumberOfParameters();

		// If operator requires a value and it's empty, return all records
		if ($numParams === 2 && $value === '') {
			return $collection;
		}

		return array_filter($collection, function ($record) use ($property, $value, $operator, $numParams) {
			$item = self::getPropertyValueForRecord($record, $property);

			if ($item === null) {
				return false;
			}

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

			if (method_exists($this, $operator)) {
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
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<string,mixed> $record
	 */
	public static function getPropertyValueForRecord(array $record, string $property): mixed
	{
		if (array_key_exists($property, $record)) {
			return $record[$property];
		}

		$properties = explode('.', $property);
		$value      = $record;
		foreach ($properties as $property) {
			if (is_array($value) && array_key_exists($property, $value)) {
				$value = $value[$property];

				if (is_array($value) && self::isIndexedArray($value)) {
					// Set $value to the first item in the array
					$value = $value[0];
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
		$found = false;
		foreach ($items as $item) {
			if (self::$operator($item, $value)) {
				$found = true;
				break;
			}
		}

		return $found;
	}

	protected static function equal(mixed $haystack, mixed $needle): bool
	{
		return $haystack === $needle;
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
		return mb_strpos(mb_strtolower($haystack), mb_strtolower($needle)) !== false;
	}

	protected static function startsCaseInsensitive(string $haystack, string $needle): bool
	{
		return mb_strpos(mb_strtolower($haystack), mb_strtolower($needle)) === 0;
	}

	protected static function endsCaseInsensitive(string $haystack, string $needle): bool
	{
		$length = mb_strlen($needle);

		return $length > 0 ? mb_substr(mb_strtolower($haystack), -$length) === mb_strtolower($needle) : true;
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

	/** @SuppressWarnings(PHPMD.ShortMethodName) */
	protected static function lt(string $haystack, string $needle): bool
	{
		return self::less($haystack, $needle);
	}

	/** @SuppressWarnings(PHPMD.ShortMethodName) */
	protected static function le(string $haystack, string $needle): bool
	{
		return self::lesseq($haystack, $needle);
	}

	/** @SuppressWarnings(PHPMD.ShortMethodName) */
	protected static function gt(string $haystack, string $needle): bool
	{
		return self::greater($haystack, $needle);
	}

	/** @SuppressWarnings(PHPMD.ShortMethodName) */
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

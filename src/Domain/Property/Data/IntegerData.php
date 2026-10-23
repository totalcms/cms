<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Integer type property data: a number stored as a whole number.
 *
 * `"type": "integer"` is JSON Schema's own type, and the object is validated
 * against the schema AFTER its properties are transformed, so a fractional
 * value is kept as it came in for the validator to reject with a proper
 * message rather than silently truncated here. A whole number is stored as
 * `3`, not `3.0`. A sibling of NumberData rather than a child: PHP will not
 * let an int return narrow NumberData's float.
 */
class IntegerData extends PropertyData implements \Stringable
{
	public int|float $number;

	public function __construct(string|int|float $number = 0, public array $settings = [])
	{
		$float        = floatval($number);
		$this->number = floor($float) === $float ? (int)$float : $float;
	}

	public function transform(): int|float
	{
		return $this->number;
	}

	public function __toString(): string
	{
		return (string)$this->number;
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		if (isset($default) && $value === null) {
			// Set the value from the schema default
			return intval($default);
		}

		return $value;
	}
}

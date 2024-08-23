<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Number type property data.
 */
class NumberData extends PropertyData
{
	public float $number;

	public function __construct(mixed $number = 0)
	{
		$this->number = floatval($number);
	}

	public function transform(): float
	{
		return $this->number;
	}

	public function __toString(): string
	{
		return (string)$this->number;
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		if (isset($default)) {
			if ($value === null) {
				// Set the value from the schema default
				$value = floatval($default);
			}
		}

		return $value;
	}
}

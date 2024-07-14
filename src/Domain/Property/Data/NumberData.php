<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Number type property data.
 */
class NumberData extends PropertyData
{
	public float $number;

	public function __construct(float $number)
	{
		$this->number = $number;
	}

	public function transform(): float
	{
		return $this->number;
	}

	public function __toString(): string
	{
		return (string)$this->number;
	}
}

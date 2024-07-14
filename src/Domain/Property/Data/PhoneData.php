<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Phone Number type property data.
 */
class PhoneData extends PropertyData
{
	public string $phone;

	public function __construct(string $phone)
	{
		$this->phone = $phone;
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->phone;
	}
}

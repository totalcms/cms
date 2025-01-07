<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Phone Number type property data.
 */
class PhoneData extends PropertyData
{
	public function __construct(public string $phone, public array $settings = [])
	{
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

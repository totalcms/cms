<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Phone Number type property data.
 */
class PhoneData extends PropertyData implements \Stringable
{
	public function __construct(public string $phone = '', public array $settings = [])
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

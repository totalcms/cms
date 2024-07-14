<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class StringData extends PropertyData
{
	public function __construct(
		public string $text = ''
	) {
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->text;
	}
}

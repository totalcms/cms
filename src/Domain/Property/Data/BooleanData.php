<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Boolean type property data.
 */
class BooleanData extends PropertyData
{
	public bool $status;

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(bool $status = false, public array $settings = [])
	{
		$this->status = $status;
	}

	public function transform(): bool
	{
		return $this->status;
	}

	public function __toString(): string
	{
		return $this->status ? 'true' : 'false';
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		if (isset($default)) {
			if ($value === null) {
				// Set the value from the schema default
				$value = boolval($default);
			}
		}

		return $value;
	}
}

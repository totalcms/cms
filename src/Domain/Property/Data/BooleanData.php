<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Boolean type property data.
 */
class BooleanData extends PropertyData implements \Stringable
{
	public bool $status;

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(int|string|bool $status = false, public array $settings = [])
	{
		if (is_string($status)) {
			$status = in_array(strtolower($status), ['true', '1', 'yes'], true);
		}
		if (is_int($status)) {
			$status = $status === 1;
		}
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
		if (isset($default) && $value === null) {
			// Set the value from the schema default
			$value = boolval($default);
		}

		return $value;
	}
}

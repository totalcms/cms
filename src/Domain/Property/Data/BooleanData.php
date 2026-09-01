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
			$status = self::isTruthy($status);
		}
		if (is_int($status)) {
			$status = $status === 1;
		}
		$this->status = $status;
	}

	/**
	 * Which strings count as true, in one place.
	 *
	 * Used by the constructor and by ObjectImporter, which has to coerce card
	 * sub-values itself — CardData stores them verbatim, with no property type
	 * doing it on the way past.
	 */
	public static function isTruthy(string $value): bool
	{
		return in_array(strtolower($value), ['true', '1', 'yes'], true);
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
			return boolval($default);
		}

		return $value;
	}
}

<?php

namespace TotalCMS\Domain\Property\Data;

/**
 *  @SuppressWarnings("PHPMD.NumberOfChildren")
 */
class PropertyData implements PropertyDataInterface
{
	/** @param array<string,mixed> $settings */
	public function __construct(public string $id, public array $settings = [])
	{
	}

	/**
	 * transform the property data.
	 *
	 * @return mixed
	 */
	public function transform(): mixed
	{
		return null;
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		if (isset($default)) {
			if ($value === null) {
				// Set the value from the schema default
				$value = $default;
			}
		}

		return $value;
	}

	public function __toString(): string
	{
		return $this->id;
	}
}

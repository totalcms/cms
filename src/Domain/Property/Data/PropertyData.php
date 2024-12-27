<?php

namespace TotalCMS\Domain\Property\Data;

/**
 *  @SuppressWarnings("PHPMD.NumberOfChildren")
 */
class PropertyData implements PropertyDataInterface
{
	public string $id;
	/** @var array<string,mixed> */
	public array $settings;

	/** @param array<string,mixed> $settings */
	public function __construct(string $id, array $settings = [])
	{
		$this->id = $id;
		$this->settings = $settings;
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

	public function actionsBeforeSave(): PropertyData
	{
		return $this;
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
}

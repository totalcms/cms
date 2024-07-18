<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Property data.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
class PropertyData implements PropertyDataInterface
{
	public const PROPERTY_TYPES = [
		'string',
		'number',
		'boolean',
		'array',
		// 'object',
		'color',
		'date',
		'time',
		'deck',
		'email',
		'file',
		'image',
		'url',
		'depot',
		'gallery',
		'list',
		'password',
		'phone',
		'slug',
		'svg',
	];

	public string $id;

	public function __construct(string $id)
	{
		$this->id = $id;
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
}

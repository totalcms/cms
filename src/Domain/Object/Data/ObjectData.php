<?php

namespace TotalCMS\Domain\Object\Data;

use Illuminate\Support\Collection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Data\SlugData;

/**
 * Data collection object.
 */
class ObjectData
{
	// Reserved names that cannot be used for objects
	// these are used in that sub URLs in the admin dashboard
	public const RESERVED_NAMES = [
		'index',
		'add',
		'edit',
		'id',
	];

	public string $id;
	/** @var Collection<string,PropertyData> */
	public Collection $properties;
	protected Serializer $serializer;

	/** @param array<string,mixed> $properties */
	public function __construct(string $id, array $properties)
	{
		$this->id         = SlugData::slugify($id);
		$this->properties = new Collection($properties);
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$base = ['id' => $this->id];

		// Transform properties
		$properties = $this->properties->map(fn ($property) => $property->transform());

		return array_merge($base, $properties->toArray());
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
	}

	/** @return array<string> */
	public function forCsv(): array
	{
		$properties = $this->properties->map(function ($property) {
			$value = strval($property);

			// Escape newlines for CSV compatibility by converting to literal \n
			// This preserves newlines in a CSV-safe format that can be parsed back
			return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $value);
		});
		$properties['id'] = $this->id;

		return $properties->toArray();
	}
}

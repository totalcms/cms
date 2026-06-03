<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Data;

/**
 * Narrows a repair run to specific field types and/or property names. Empty
 * lists mean "all".
 */
final readonly class RepairFilters
{
	/**
	 * @param list<string> $types      file|image|gallery|depot (empty = all)
	 * @param list<string> $properties property names (empty = all)
	 */
	public function __construct(
		public array $types = [],
		public array $properties = [],
	) {
	}

	public function allowsType(string $type): bool
	{
		return $this->types === [] || in_array($type, $this->types, true);
	}

	public function allowsProperty(string $property): bool
	{
		return $this->properties === [] || in_array($property, $this->properties, true);
	}
}

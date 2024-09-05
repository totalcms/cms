<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class DepotData extends PropertyData
{
	/** @param array<array<string,mixed>> $files */
	public function __construct(
		public array $files = [],
	) {
	}

	/** @return array<array<string,mixed>> */
	public function transform(): array
	{
		return $this->files;
	}
}

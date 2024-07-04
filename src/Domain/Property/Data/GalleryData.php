<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class GalleryData extends PropertyData
{
	/** @param array<array<string,mixed>> $images */
	public function __construct(
		public array $images = []
	) {
	}

	/** @return array<array<string,mixed>> */
	public function transform(): array
	{
		return $this->images;
	}
}

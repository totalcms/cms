<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class GalleryData extends PropertyData
{
	/** @param array<int,mixed> $images */
	public function __construct(
		public array $images = []
	) {
	}

	public function transform(): array
	{
		return $this->images;
	}
}

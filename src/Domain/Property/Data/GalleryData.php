<?php

namespace TotalCMS\Domain\Property\Data;

class GalleryData extends PropertyData implements \Stringable
{
	/** @var array<ImageData> */
	public array $images = [];

	/** @param array<ImageData|array<string,mixed>> $images */
	public function __construct(array $images = [], public array $settings = [])
	{
		$this->images   = array_map(
			fn (array|ImageData $image): ImageData => $image instanceof ImageData ? $image : new ImageData($image),
			$images
		);
	}

	/** @return array<array<string,mixed>> */
	public function transform(): array
	{
		return array_map(
			fn (ImageData $image): array => $image->transform(),
			$this->images
		);
	}

	public function __toString(): string
	{
		$json = json_encode($this->transform());
		if ($json === false) {
			return '';
		}

		return $json;
	}
}

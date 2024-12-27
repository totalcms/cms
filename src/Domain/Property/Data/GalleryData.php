<?php

namespace TotalCMS\Domain\Property\Data;

class GalleryData extends PropertyData
{
	/** @var array<ImageData> */
	public array $images = [];

	/**
	 * @param array<ImageData|array<string,mixed>> $images
	 * @param array<string,mixed> $settings
	*/
	public function __construct(array $images = [], array $settings = [])
	{
		$this->settings = $settings;
		$this->images   = array_map(
			fn ($image) => $image instanceof ImageData ? $image : new ImageData($image),
			$images
		);
	}

	/** @return array<array<string,mixed>> */
	public function transform(): array
	{
		return array_map(
			fn (ImageData $image) => $image->transform(),
			$this->images
		);
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * Per-render facts handed to a filter alongside the image.
 */
final readonly class FilterContext
{
	/**
	 * @param string                   $driver  Image driver in use: 'gd' or 'imagick'
	 * @param list<string>             $palette Hex colors extracted from the source image (may be empty)
	 * @param array{string,string}|null $duo    Resolved duotone [shadow, highlight] hex colors, or null
	 */
	public function __construct(
		public string $driver,
		public array $palette,
		public ?array $duo,
	) {
	}
}

<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Where one ImageWorks source image lives: the property folder of an object,
 * plus the subpath of a card, deck or video child image.
 */
final readonly class ImageLocation
{
	public function __construct(
		public string $collection,
		public string $id,
		public string $property,
		public ?string $subpath = null,
	) {
	}

	/** The folder the image sits in, or the file itself when `$file` is given. */
	public function path(?string $file = null): string
	{
		return PathUtils::buildPath($this->collection, $this->id, $this->property, $file, $this->subpath);
	}
}
